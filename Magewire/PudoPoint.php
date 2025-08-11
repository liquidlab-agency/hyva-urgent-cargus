<?php
/**
 * Liquidlab HyvaCargus - Hyva Checkout compatibility for Urgent CargusShipGo
 */

declare(strict_types=1);

namespace Liquidlab\HyvaCargus\Magewire;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;
use Urgent\CargusShipGo\Api\PudoRepositoryInterface;
use Urgent\CargusShipGo\Model\CheckoutConfigProvider;

class PudoPoint extends Component implements EvaluationInterface
{
    public const CARGUS_PUDO_SESSION_KEY = 'cargus_selected_pudo_point';
    
    public string $pudoId = '';
    public string $pudoName = '';
    public string $pudoAddress = '';
    public string $pudoCity = '';
    public string $pudoLatitude = '';
    public string $pudoLongitude = '';
    public string $pudoType = '';
    public string $paymentInfo = '';

    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger,
        private readonly CheckoutConfigProvider $cargusConfigProvider,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly PudoRepositoryInterface $pudoRepository
    ) {
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingMethod = $quote->getShippingAddress()->getShippingMethod();
            
            // Check if this is a Cargus shipping method that requires pudo selection
            if (!$this->isCargusShippingMethod($shippingMethod)) {
                return $resultFactory->createSuccess();
            }

            if (empty($this->pudoId)) {
                return $resultFactory->createErrorMessageEvent((string)__('Please select a pickup point location.'))
                    ->withCustomEvent('shipping:method:error');
            }

            // Save pudo point data to quote
            $this->savePudoPointToQuote();

            return $resultFactory->createSuccess();
            
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus PudoPoint evaluation error: ' . $e->getMessage());
            return $resultFactory->createErrorMessageEvent((string)__('An error occurred while processing the pickup point selection.'))
                ->withCustomEvent('shipping:method:error');
        }
    }

    public function mount(): void
    {
        try {
            // Load existing pudo point data from session
            $pudoData = $this->sessionCheckout->getData(self::CARGUS_PUDO_SESSION_KEY);
            if (is_array($pudoData)) {
                $this->pudoId = $pudoData['pudo_id'] ?? '';
                $this->pudoName = $pudoData['name'] ?? '';
                $this->pudoAddress = $pudoData['address'] ?? '';
                $this->pudoCity = $pudoData['city'] ?? '';
                $this->pudoLatitude = $pudoData['latitude'] ?? '';
                $this->pudoLongitude = $pudoData['longitude'] ?? '';
                $this->pudoType = $pudoData['type'] ?? '';
                $this->paymentInfo = $pudoData['payment_info'] ?? '';
            }
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus PudoPoint mount error: ' . $e->getMessage());
        }
    }

    public function selectPudoPoint(string $pudoId): void 
    {
        try {
            // Load PUDO data from database using the repository
            $pudo = $this->pudoRepository->getByPudoId((int)$pudoId);
            
            // Extract all data from the loaded PUDO model
            $this->pudoId = (string)$pudo->getPudoId();
            $this->pudoName = $pudo->getName();
            $this->pudoAddress = $pudo->getAddress() ?? '';
            $this->pudoCity = $pudo->getCounty();
            $this->pudoLatitude = (string)$pudo->getLatitude();
            $this->pudoLongitude = (string)$pudo->getLongitude();
            $this->pudoType = (string)$pudo->getPointType();
            
            // Format payment info from accepted payment type
            $acceptedPaymentType = $pudo->getAcceptedPaymentType();
            $this->paymentInfo = $this->formatPaymentInfo($acceptedPaymentType);

            // Save to session with data from database
            $this->sessionCheckout->setData(self::CARGUS_PUDO_SESSION_KEY, [
                'pudo_id' => $this->pudoId,
                'name' => $this->pudoName,
                'address' => $this->pudoAddress,
                'city' => $this->pudoCity,
                'latitude' => $this->pudoLatitude,
                'longitude' => $this->pudoLongitude,
                'type' => $this->pudoType,
                'payment_info' => $this->paymentInfo
            ]);

            // Update shipping address with PUDO point address
            $this->updateShippingAddressWithPudo($this->pudoId, $this->pudoName, $this->pudoAddress);
            
            $this->logger->info('HyvaCargus: Successfully loaded PUDO from database: ' . $this->pudoName . ' (ID: ' . $pudoId . ')');
            
        } catch (NoSuchEntityException $e) {
            $this->logger->error('HyvaCargus: PUDO not found in database: ' . $pudoId . ' - ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to load PUDO from database: ' . $e->getMessage());
            throw $e;
        }
    }

    public function clearPudoPoint(): void
    {
        $this->pudoId = '';
        $this->pudoName = '';
        $this->pudoAddress = '';
        $this->pudoCity = '';
        $this->pudoLatitude = '';
        $this->pudoLongitude = '';
        $this->pudoType = '';
        $this->paymentInfo = '';

        $this->sessionCheckout->unsetData(self::CARGUS_PUDO_SESSION_KEY);
        
        // Restore original customer shipping address
        $this->restoreOriginalShippingAddress();
    }


    public function hasSelectedPudo(): bool
    {
        return !empty($this->pudoId);
    }

    public function getSelectedPudoInfo(): string
    {
        if (!$this->hasSelectedPudo()) {
            return '';
        }

        $info = $this->pudoName;
        if (!empty($this->pudoAddress)) {
            $info .= ' - ' . $this->pudoAddress;
        }
        
        return $info;
    }

    public function getCargusData(): array
    {
        try {
            $config = $this->cargusConfigProvider->getConfig();
            $cargusData = $config['cargus'] ?? [];
            
            // Add customer location for map centering
            $customerLocation = $this->getCustomerLocation();
            if ($customerLocation) {
                $cargusData['customerLocation'] = $customerLocation;

                // Filter PUDO points by distance to improve performance
                // Only show points within reasonable distance from customer location
                if (isset($cargusData['pins']) && is_array($cargusData['pins'])) {
                    $cargusData['pins'] = $this->filterPudoPointsByDistance(
                        $cargusData['pins'],
                        $customerLocation,
                        50 // 50km radius - configurable
                    );

                    $this->logger->info('HyvaCargus: Filtered PUDO points to ' . count($cargusData['pins']) . ' within 50km of customer location');
                }
            }
            
            return $cargusData;
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to get Cargus config data: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get customer location coordinates based on shipping address region
     * 
     * @return array|null Array with lat, lng, and region info or null if not available
     */
    public function getCustomerLocation(): ?array
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            
            if (!$shippingAddress) {
                return null;
            }
            
            // Check if this is Romania
            if ($shippingAddress->getCountryId() !== 'RO') {
                return null;
            }
            
            $regionId = $shippingAddress->getRegionId();
            if (empty($regionId)) {
                return null;
            }
            
            // Additional validation: ensure region belongs to Romania
            // This prevents potential issues if region_id doesn't match country
            if (!$this->isValidRomanianRegion((int)$regionId)) {
                $this->logger->warning('HyvaCargus: Invalid region_id for Romania: ' . $regionId);
                return null;
            }
            
            // Get coordinates for Romanian regions
            $coordinates = $this->getRegionCoordinates((int)$regionId);
            if ($coordinates) {
                return [
                    'lat' => $coordinates['lat'],
                    'lng' => $coordinates['lng'],
                    'region' => $shippingAddress->getRegion(),
                    'region_id' => $regionId
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to get customer location: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get coordinates for Romanian regions based on region_id
     * Dynamically loads regions from database and maps to coordinates
     * 
     * @param int $regionId
     * @return array|null Array with lat, lng coordinates or null if not found
     */
    private function getRegionCoordinates(int $regionId): ?array
    {
        try {
            // Load Romanian regions from database
            $romanianRegions = $this->loadRomanianRegions();
            
            return $romanianRegions[$regionId] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to load region coordinates: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Load Romanian regions from database and map to coordinates
     * Uses caching to avoid repeated database queries
     * 
     * @return array Array of region_id => coordinates mapping
     */
    private function loadRomanianRegions(): array
    {
        static $romanianRegionsCache = null;
        
        // Return cached data if available
        if ($romanianRegionsCache !== null) {
            return $romanianRegionsCache;
        }
        
        try {
            // Load Romanian regions from database
            $regionCollection = $this->regionCollectionFactory->create();
            $regionCollection->addCountryFilter('RO'); // Filter by Romania
            
            // Create coordinate mapping based on region data
            $romanianRegionsCache = [];
            
            foreach ($regionCollection as $region) {
                $coordinates = $this->getCoordinatesForRegion($region);
                if ($coordinates) {
                    $romanianRegionsCache[(int)$region->getRegionId()] = $coordinates;
                }
            }
            
            return $romanianRegionsCache;
            
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to load Romanian regions from database: ' . $e->getMessage());
            
            // Fallback to essential regions if database loading fails
            $romanianRegionsCache = $this->getFallbackRegionCoordinates();
            return $romanianRegionsCache;
        }
    }

    /**
     * Get coordinates for a specific region based on region code or name
     * Maps region codes to county capital coordinates
     * 
     * @param \Magento\Directory\Model\Region $region
     * @return array|null Array with lat, lng coordinates or null if not found
     */
    private function getCoordinatesForRegion(\Magento\Directory\Model\Region $region): ?array
    {
        // Map region codes to county capital coordinates
        $regionCodeMapping = [
            // Major Romanian counties and their capitals
            'AB' => ['lat' => 46.0667, 'lng' => 23.5833], // Alba -> Alba Iulia
            'AR' => ['lat' => 46.1866, 'lng' => 21.3123], // Arad -> Arad
            'AG' => ['lat' => 44.8565, 'lng' => 24.8692], // Argeș -> Pitești
            'BC' => ['lat' => 46.5670, 'lng' => 26.9146], // Bacău -> Bacău
            'BH' => ['lat' => 47.0465, 'lng' => 21.9189], // Bihor -> Oradea
            'BN' => ['lat' => 47.1333, 'lng' => 24.5000], // Bistrița-Năsăud -> Bistrița
            'BT' => ['lat' => 47.7414, 'lng' => 26.6617], // Botoșani -> Botoșani
            'BV' => ['lat' => 45.6427, 'lng' => 25.5887], // Brașov -> Brașov
            'BR' => ['lat' => 45.2692, 'lng' => 27.9574], // Brăila -> Brăila
            'B' => ['lat' => 44.4268, 'lng' => 26.1025],  // București -> București
            'BZ' => ['lat' => 45.1500, 'lng' => 26.8333], // Buzău -> Buzău
            'CL' => ['lat' => 44.2000, 'lng' => 27.3333], // Călărași -> Călărași
            'CS' => ['lat' => 45.3000, 'lng' => 21.8833], // Caraș-Severin -> Reșița
            'CJ' => ['lat' => 46.7712, 'lng' => 23.6236], // Cluj -> Cluj-Napoca
            'CT' => ['lat' => 44.1598, 'lng' => 28.6348], // Constanța -> Constanța
            'CV' => ['lat' => 45.8667, 'lng' => 25.7833], // Covasna -> Sfântu Gheorghe
            'DB' => ['lat' => 44.9167, 'lng' => 25.4500], // Dâmbovița -> Târgoviște
            'DJ' => ['lat' => 44.3302, 'lng' => 23.7949], // Dolj -> Craiova
            'GL' => ['lat' => 45.4353, 'lng' => 28.0080], // Galați -> Galați
            'GR' => ['lat' => 43.9000, 'lng' => 25.9667], // Giurgiu -> Giurgiu
            'GJ' => ['lat' => 45.0333, 'lng' => 23.2833], // Gorj -> Târgu Jiu
            'HR' => ['lat' => 46.3667, 'lng' => 25.8000], // Harghita -> Miercurea Ciuc
            'HD' => ['lat' => 45.7500, 'lng' => 22.9000], // Hunedoara -> Deva
            'IL' => ['lat' => 44.1667, 'lng' => 27.9500], // Ialomița -> Slobozia
            'IS' => ['lat' => 47.1585, 'lng' => 27.6014], // Iași -> Iași
            'IF' => ['lat' => 44.4500, 'lng' => 26.1000], // Ilfov -> București
            'MM' => ['lat' => 47.6587, 'lng' => 23.5681], // Maramureș -> Baia Mare
            'MH' => ['lat' => 44.6333, 'lng' => 22.6667], // Mehedinți -> Drobeta-Turnu Severin
            'MS' => ['lat' => 46.5427, 'lng' => 24.5574], // Mureș -> Târgu Mureș
            'NT' => ['lat' => 46.9266, 'lng' => 26.3699], // Neamț -> Piatra Neamț
            'OT' => ['lat' => 44.4333, 'lng' => 24.3667], // Olt -> Slatina
            'PH' => ['lat' => 44.9414, 'lng' => 26.0297], // Prahova -> Ploiești
            'SM' => ['lat' => 47.7903, 'lng' => 22.8756], // Satu Mare -> Satu Mare
            'SJ' => ['lat' => 47.2000, 'lng' => 23.0667], // Sălaj -> Zalău
            'SB' => ['lat' => 45.7983, 'lng' => 24.1256], // Sibiu -> Sibiu
            'SV' => ['lat' => 47.6514, 'lng' => 26.2551], // Suceava -> Suceava
            'TR' => ['lat' => 43.9833, 'lng' => 25.3333], // Teleorman -> Alexandria
            'TM' => ['lat' => 45.7489, 'lng' => 21.2087], // Timiș -> Timișoara
            'TL' => ['lat' => 45.1667, 'lng' => 28.8000], // Tulcea -> Tulcea
            'VL' => ['lat' => 45.1167, 'lng' => 24.3667], // Vâlcea -> Râmnicu Vâlcea
            'VS' => ['lat' => 46.6333, 'lng' => 27.7333], // Vaslui -> Vaslui
            'VN' => ['lat' => 45.1000, 'lng' => 26.6333], // Vrancea -> Focșani
        ];
        
        $regionCode = $region->getCode();
        return $regionCodeMapping[$regionCode] ?? null;
    }

    /**
     * Get fallback region coordinates for essential Romanian regions
     * Used when database loading fails
     * 
     * @return array Array of region_id => coordinates mapping
     */
    private function getFallbackRegionCoordinates(): array
    {
        return [
            // These are example mappings - actual region_ids should be verified against your database
            287 => ['lat' => 44.4268, 'lng' => 26.1025], // București
            291 => ['lat' => 46.7712, 'lng' => 23.6236], // Cluj-Napoca
            315 => ['lat' => 45.7489, 'lng' => 21.2087], // Timișoara
            302 => ['lat' => 47.1585, 'lng' => 27.6014], // Iași
            292 => ['lat' => 44.1598, 'lng' => 28.6348], // Constanța
            285 => ['lat' => 45.6427, 'lng' => 25.5887],  // Brașov
            296 => ['lat' => 45.4353, 'lng' => 28.0080], // Galați
        ];
    }

    /**
     * Validate that the region_id belongs to Romania
     * This provides additional security by checking our mapping against known Romanian regions
     * 
     * @param int $regionId
     * @return bool
     */
    private function isValidRomanianRegion(int $regionId): bool
    {
        // Check if the region_id exists in our Romanian regions mapping
        // This serves as validation that the region belongs to Romania
        $coordinates = $this->getRegionCoordinates($regionId);
        return $coordinates !== null;
    }

    private function isCargusShippingMethod(?string $shippingMethod): bool
    {
        if (empty($shippingMethod)) {
            return false;
        }

        // Check if this is a Cargus shipping method that requires pudo selection
        return strpos($shippingMethod, 'cargus_shipandgo') !== false;
    }

    /**
     * Filter PUDO points by distance from customer location to improve performance
     * Only returns points within the specified radius
     * 
     * @param array $pudoPoints Array of PUDO points
     * @param array $customerLocation Customer location with lat/lng
     * @param float $radiusKm Maximum distance in kilometers
     * @return array Filtered array of PUDO points
     */
    private function filterPudoPointsByDistance(array $pudoPoints, array $customerLocation, float $radiusKm): array
    {
        if (empty($pudoPoints) || empty($customerLocation['lat']) || empty($customerLocation['lng'])) {
            return $pudoPoints;
        }

        $customerLat = (float)$customerLocation['lat'];
        $customerLng = (float)$customerLocation['lng'];
        $filteredPoints = [];

        foreach ($pudoPoints as $point) {
            if (empty($point['latitude']) || empty($point['longitude'])) {
                continue;
            }

            $pointLat = (float)$point['latitude'];
            $pointLng = (float)$point['longitude'];

            $distance = $this->calculateDistance($customerLat, $customerLng, $pointLat, $pointLng);

            if ($distance <= $radiusKm) {
                // Add distance to the point data for potential sorting
                $point['distance_km'] = round($distance, 2);
                $filteredPoints[] = $point;
            }
        }

        // Sort by distance (closest first)
        usort($filteredPoints, function($a, $b) {
            return $a['distance_km'] <=> $b['distance_km'];
        });

        return $filteredPoints;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * 
     * @param float $lat1 Latitude of first point
     * @param float $lng1 Longitude of first point
     * @param float $lat2 Latitude of second point
     * @param float $lng2 Longitude of second point
     * @return float Distance in kilometers
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function savePudoPointToQuote(): void
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            
            // Save pudo point data to quote using extension attributes or custom field
            // This follows the same pattern as the original CargusShipGo module
            $pudoData = [
                'pudo_id' => $this->pudoId,
                'name' => $this->pudoName,
                'address' => $this->pudoAddress,
                'latitude' => $this->pudoLatitude,
                'longitude' => $this->pudoLongitude,
                'type' => $this->pudoType,
                'payment_info' => $this->paymentInfo
            ];

            // Save to quote - this will be handled by the original module's mechanisms
            $quote->setData('cargus_selected_pudo_point', json_encode($pudoData));
            $this->quoteRepository->save($quote);
            
        } catch (LocalizedException $e) {
            $this->logger->error('HyvaCargus: Failed to save pudo point to quote: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update shipping address with PUDO point address
     * This replaces the customer's shipping address with the PUDO point address
     * 
     * @param string $pudoId
     * @param string $name
     * @param string $address
     * @return void
     */
    private function updateShippingAddressWithPudo(string $pudoId, string $name, string $address): void
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            
            if (!$shippingAddress) {
                $this->logger->warning('HyvaCargus: No shipping address found to update with PUDO');
                return;
            }

            // Get the original country before saving original address data
            $originalCountryId = $shippingAddress->getCountryId();

            // Save original address data before replacing it
            $this->saveOriginalShippingAddress($shippingAddress);

            // Parse PUDO address - format is typically: "CITY, Street Name, Nr. X, Postal Code"
            $addressParts = $this->parsePudoAddress($address);
            
            // Update shipping address with PUDO details
            $shippingAddress->setCompany($name); // Store PUDO name in company field
            $shippingAddress->setStreet([$addressParts['street'] ?? $address]); // Use parsed street or full address
            $shippingAddress->setCity($addressParts['city'] ?? 'Unknown');
            $shippingAddress->setPostcode($addressParts['postal_code'] ?? '');
            
            // Preserve the original customer's country instead of hardcoding 'RO'
            // This follows Hyva checkout best practices for country handling
            $countryToUse = $originalCountryId ?: 'RO'; // Fallback to 'RO' only if no original country
            $shippingAddress->setCountryId($countryToUse);
            
            // Set PUDO ID for the existing plugins to work
            $shippingAddress->setPudoId($pudoId);
            
            // Save the updated quote
            $this->quoteRepository->save($quote);
            
            $this->logger->info('HyvaCargus: Updated shipping address with PUDO point: ' . $name . ' (City: ' . ($addressParts['city'] ?? 'Unknown') . ', Country: ' . $countryToUse . ')');
            
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to update shipping address with PUDO: ' . $e->getMessage());
        }
    }

    /**
     * Restore original customer shipping address
     * This restores the customer's original address when PUDO is cleared
     * 
     * @return void
     */
    private function restoreOriginalShippingAddress(): void
    {
        try {
            $originalAddressData = $this->sessionCheckout->getData('original_shipping_address');
            
            if (!$originalAddressData) {
                $this->logger->info('HyvaCargus: No original address to restore');
                return;
            }

            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            
            if (!$shippingAddress) {
                $this->logger->warning('HyvaCargus: No shipping address found to restore');
                return;
            }

            // Restore original address data
            $shippingAddress->setCompany($originalAddressData['company'] ?? '');
            $shippingAddress->setStreet($originalAddressData['street'] ?? []);
            $shippingAddress->setCity($originalAddressData['city'] ?? '');
            $shippingAddress->setPostcode($originalAddressData['postcode'] ?? '');
            $shippingAddress->setCountryId($originalAddressData['country_id'] ?? '');
            $shippingAddress->setRegionId($originalAddressData['region_id'] ?? null);
            $shippingAddress->setRegion($originalAddressData['region'] ?? '');
            
            // Clear PUDO ID
            $shippingAddress->setPudoId(null);
            
            // Save the updated quote
            $this->quoteRepository->save($quote);
            
            // Clear the saved original address
            $this->sessionCheckout->unsetData('original_shipping_address');
            
            $this->logger->info('HyvaCargus: Restored original shipping address');
            
        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus: Failed to restore original shipping address: ' . $e->getMessage());
        }
    }

    /**
     * Save original shipping address data before replacing with PUDO
     * 
     * @param \Magento\Quote\Api\Data\AddressInterface $shippingAddress
     * @return void
     */
    private function saveOriginalShippingAddress($shippingAddress): void
    {
        // Only save if we haven't already saved the original address
        if (!$this->sessionCheckout->getData('original_shipping_address')) {
            $originalAddressData = [
                'company' => $shippingAddress->getCompany(),
                'street' => $shippingAddress->getStreet(),
                'city' => $shippingAddress->getCity(),
                'postcode' => $shippingAddress->getPostcode(),
                'country_id' => $shippingAddress->getCountryId(),
                'region_id' => $shippingAddress->getRegionId(),
                'region' => $shippingAddress->getRegion(),
            ];
            
            $this->sessionCheckout->setData('original_shipping_address', $originalAddressData);
            $this->logger->info('HyvaCargus: Saved original shipping address for restoration');
        }
    }

    /**
     * Parse PUDO address string into components
     * Expected format: "CITY, Street Name, Nr. X, Postal Code"
     * 
     * @param string $address
     * @return array
     */
    private function parsePudoAddress(string $address): array
    {
        $result = [
            'city' => '',
            'street' => '',
            'postal_code' => ''
        ];

        try {
            // Split by comma and clean up parts
            $parts = array_map('trim', explode(',', $address));
            
            if (count($parts) >= 1) {
                $result['city'] = $parts[0];
            }
            
            if (count($parts) >= 2) {
                // Combine street parts (everything except first and last if postal code is present)
                $streetParts = array_slice($parts, 1);
                
                // Check if last part looks like a postal code (digits)
                $lastPart = end($streetParts);
                if (preg_match('/\b\d{6}\b/', $lastPart)) {
                    $result['postal_code'] = trim(preg_replace('/.*?(\d{6}).*/', '$1', $lastPart));
                    array_pop($streetParts); // Remove postal code from street parts
                }
                
                $result['street'] = implode(', ', $streetParts);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('HyvaCargus: Failed to parse PUDO address: ' . $e->getMessage());
            $result['street'] = $address; // Fallback to full address
        }

        return $result;
    }

    /**
     * Format payment info from accepted payment type JSON
     * 
     * @param string|null $acceptedPaymentType
     * @return string
     */
    private function formatPaymentInfo(?string $acceptedPaymentType): string
    {
        if (empty($acceptedPaymentType)) {
            return '';
        }

        try {
            $payments = json_decode($acceptedPaymentType, true);
            if (!is_array($payments)) {
                return '';
            }

            $info = [];
            if (!empty($payments['Cash'])) {
                $info[] = __('Cash')->render();
            }
            if (!empty($payments['Card'])) {
                $info[] = __('Card')->render();
            }
            if (!empty($payments['Online'])) {
                $info[] = __('Online')->render();
            }

            return !empty($info) ? __('Payment methods')->render() . ': ' . implode(', ', $info) : '';
        } catch (\Exception $e) {
            $this->logger->warning('HyvaCargus: Failed to parse payment info: ' . $e->getMessage());
            return '';
        }
    }
}