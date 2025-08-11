<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\HyvaCargus\Observer;

use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class CheckoutSubmitBefore implements ObserverInterface
{
    public function __construct(
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ensure PUDO address is properly set in quote before order submission begins
     * This runs at checkout_submit_before event, which is EARLIER than sales_model_service_quote_submit_before
     * This prevents any address restoration that might happen during the order submission process
     * 
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Quote $quote */
            $quote = $observer->getData('quote');

            if (!$quote) {
                return;
            }

            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
                return;
            }

            // Check if this is a Cargus shipping method
            $shippingMethod = $shippingAddress->getShippingMethod();
            if (!$this->isCargusShippingMethod($shippingMethod)) {
                return;
            }

            // Check if PUDO data exists in quote or session
            $pudoData = $this->getPudoDataFromQuote($quote);
            if (!$pudoData) {
                $this->logger->debug('HyvaCargus CheckoutSubmitBefore: No PUDO data found for Cargus shipping method', [
                    'quote_id' => $quote->getId(),
                    'shipping_method' => $shippingMethod
                ]);
                return;
            }

            // Update shipping address with PUDO data BEFORE order submission begins
            $this->updateShippingAddressWithPudoData($shippingAddress, $pudoData);

        } catch (\Exception $e) {
            $this->logger->error('HyvaCargus CheckoutSubmitBefore: Failed to update shipping address with PUDO data: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Check if the shipping method is a Cargus method that requires PUDO selection
     * 
     * @param string|null $shippingMethod
     * @return bool
     */
    private function isCargusShippingMethod(?string $shippingMethod): bool
    {
        if (!$shippingMethod) {
            return false;
        }

        // Check for Cargus shipping methods (based on original JavaScript logic)
        $cargusShippingMethods = [
            'cargus_shipandgo_cargus_shipandgo',
            'cargus_shipandgo'
        ];

        foreach ($cargusShippingMethods as $method) {
            if (strpos($shippingMethod, $method) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get PUDO data from quote and session
     * This checks multiple sources: quote custom data, session data, and shipping address
     * 
     * @param Quote $quote
     * @return array|null
     */
    private function getPudoDataFromQuote(Quote $quote): ?array
    {
        // First, try to get PUDO data from quote custom data (set by HyvaCargus component)
        $pudoSelectionData = $quote->getData('cargus_selected_pudo_point');
        if ($pudoSelectionData) {
            $pudoData = json_decode($pudoSelectionData, true);
            if (is_array($pudoData) && !empty($pudoData['pudo_id'])) {
                $this->logger->debug('HyvaCargus CheckoutSubmitBefore: Found PUDO data in quote custom data');
                return $pudoData;
            }
        }

        // Check for newer PUDO selection format
        $pudoSelection = $quote->getData('cargus_pudo_selection');
        if ($pudoSelection) {
            $pudoData = json_decode($pudoSelection, true);
            if (is_array($pudoData) && !empty($pudoData['pudo_id'])) {
                $this->logger->debug('HyvaCargus CheckoutSubmitBefore: Found PUDO data in quote pudo selection');
                return $pudoData;
            }
        }

        // Fallback: try to get from session data
        $sessionPudoData = $this->sessionCheckout->getData('cargus_selected_pudo_point');
        if (is_array($sessionPudoData) && !empty($sessionPudoData['pudo_id'])) {
            $this->logger->debug('HyvaCargus CheckoutSubmitBefore: Found PUDO data in session');
            return $sessionPudoData;
        }

        // Check if shipping address already has PUDO ID set
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress && $shippingAddress->getPudoId()) {
            return [
                'pudo_id' => $shippingAddress->getPudoId(),
                'name' => $shippingAddress->getCompany() ?: 'PUDO Point',
                'address' => implode(', ', $shippingAddress->getStreet() ?: []),
                'city' => $shippingAddress->getCity(),
                'postal_code' => $shippingAddress->getPostcode()
            ];
        }

        return null;
    }

    /**
     * Update shipping address with PUDO data
     * This ensures the PUDO address is properly set before order submission begins
     * 
     * @param \Magento\Quote\Api\Data\AddressInterface $shippingAddress
     * @param array $pudoData
     * @return void
     */
    private function updateShippingAddressWithPudoData($shippingAddress, array $pudoData): void
    {
        // Parse PUDO address if it's a full address string
        $addressParts = $this->parsePudoAddress($pudoData['address'] ?? '');

        // Update shipping address with PUDO details
        $shippingAddress->setCompany($pudoData['name'] ?? 'PUDO Point');
        
        // Set street address
        if (!empty($addressParts['street'])) {
            $shippingAddress->setStreet([$addressParts['street']]);
        } elseif (!empty($pudoData['address'])) {
            $shippingAddress->setStreet([$pudoData['address']]);
        }

        // Set city
        if (!empty($addressParts['city'])) {
            $shippingAddress->setCity($addressParts['city']);
        } elseif (!empty($pudoData['city'])) {
            $shippingAddress->setCity($pudoData['city']);
        }

        // Set postal code
        if (!empty($addressParts['postal_code'])) {
            $shippingAddress->setPostcode($addressParts['postal_code']);
        } elseif (!empty($pudoData['postal_code'])) {
            $shippingAddress->setPostcode($pudoData['postal_code']);
        }

        // Set PUDO ID for compatibility with existing plugins
        if (!empty($pudoData['pudo_id'])) {
            $shippingAddress->setPudoId($pudoData['pudo_id']);
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

        if (empty($address)) {
            return $result;
        }

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
            $this->logger->warning('HyvaCargus CheckoutSubmitBefore: Failed to parse PUDO address: ' . $e->getMessage());
            $result['street'] = $address; // Fallback to full address
        }

        return $result;
    }
}