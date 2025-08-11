# Liquidlab HyvaCargus

Hyva Checkout compatibility module for Urgent CargusShipGo

## Overview

This module provides Hyva Checkout compatibility for the Urgent CargusShipGo shipping module. It enables the pudo point selection functionality to work seamlessly with Hyva Checkout's reactive architecture.

## Features

- **Hyva Checkout Integration**: Full compatibility with Hyva Checkout's Magewire-based architecture
- **Pudo Point Selection**: Interactive map-based selection of pickup points and lockers
- **Reactive UI**: Real-time updates when pudo points are selected or cleared
- **Validation**: Ensures pudo point selection is required for Cargus shipping methods
- **Payment Information**: Displays available payment methods for selected pickup points
- **Responsive Design**: Mobile-friendly interface with Tailwind CSS styling

## Requirements

- Magento 2.4+
- PHP 8.1+
- Urgent_CargusShipGo module (original shipping module)
- Hyva_Checkout module
- Magewirephp_Magewire module

## Installation

1. Copy the module to `app/code/Liquidlab/HyvaCargus/`
2. Run the following commands:
   ```bash
   bin/magento module:enable Liquidlab_HyvaCargus
   bin/magento setup:upgrade
   bin/magento cache:flush
   ```

## How It Works

### Architecture

The module follows Hyva's recommended patterns for shipping method integrations:

1. **Magewire Component** (`Magewire/PudoPoint.php`): Handles the reactive logic for pudo point selection
2. **Frontend Template** (`templates/component/pudo-point.phtml`): Provides the UI for pudo point selection with Hyva modal integration
3. **Modal Content Template** (`templates/component/modal/map-content.phtml`): Contains the map interface and JavaScript functionality
4. **Layout Registration** (`layout/hyva_checkout_components.xml`): Registers the component with Hyva Checkout
5. **Config Provider Integration**: Injects the original CargusShipGo ConfigProvider to access configuration data
6. **Programmatic Modal System**: Uses Hyva's ModalBuilderInterface for advanced modal configuration with accessibility and focus management

### Integration Points

- **Shipping Method Detection**: Automatically detects Cargus shipping methods (`cargus_shipandgo*`)
- **Validation**: Validates that a pudo point is selected before checkout completion
- **Data Persistence**: Saves selected pudo point data to session and quote
- **Config Provider Compatibility**: Bypasses Hyva's lack of config provider support by injecting the original ConfigProvider directly
- **Original Module Compatibility**: Reuses existing map functionality, CSS, and JavaScript

### User Experience

1. Customer selects a Cargus shipping method in Hyva Checkout
2. The pudo point selection component appears below the shipping method
3. Customer clicks "Choose Pickup Point on Map" to open the Hyva modal with the interactive map
4. Customer selects a pudo point or locker from the interactive map within the modal
5. Modal closes automatically using Hyva's modal system and selected point information is displayed in the checkout
6. Validation ensures a point is selected before order completion

## File Structure

```
app/code/Liquidlab/HyvaCargus/
├── Magewire/
│   └── PudoPoint.php                     # Main Magewire component for pudo point selection
├── etc/
│   └── module.xml                        # Module configuration
├── view/frontend/
│   ├── layout/
│   │   └── hyva_checkout_components.xml  # Hyva component registration
│   └── templates/
│       └── component/
│           ├── pudo-point.phtml          # Main component template with Hyva modal integration
│           └── modal/
│               └── map-content.phtml     # Modal content template with map functionality
├── composer.json                         # Composer configuration
├── registration.php                      # Module registration
└── README.md                            # This file
```

## Configuration

No additional configuration is required. The module automatically:

- Detects Cargus shipping methods
- Integrates with existing CargusShipGo configuration
- Uses the original module's pudo point data and map functionality

## Customization

### Styling

The component uses Tailwind CSS classes and can be customized by:

1. Modifying the template classes in `templates/component/pudo-point.phtml`
2. Adding custom CSS to override default styles
3. Extending the Magewire component for additional functionality

### Shipping Method Detection

To support additional Cargus shipping methods, modify the `isCargusShippingMethod()` method in `Magewire/PudoPoint.php`.

### Map Functionality

The inline modal map functionality reuses the original CargusShipGo module's JavaScript and CSS. Any customizations to the map should be done in the original module.

### Config Provider Compatibility

**Problem**: Hyva Checkout doesn't use Magento's standard `ConfigProviderInterface` system, which means `window.checkoutConfig` data is not available.

**Solution**: The HyvaCargus module solves this by:

1. **Direct Injection**: Injects the original `Urgent\CargusShipGo\Model\CheckoutConfigProvider` into the Magewire component
2. **Server-Side Data Generation**: Uses `getCargusData()` method to retrieve configuration data on the server side
3. **Template Integration**: Passes data directly to the template via PHP instead of relying on JavaScript config providers

**Implementation**:
- The Magewire component includes a `getCargusData()` method that retrieves configuration from the original ConfigProvider
- The template uses PHP to generate JavaScript data instead of relying on `window.checkoutConfig.cargus`
- All map functionality (icons, pins, etc.) is preserved but accessed through server-side generated data

This approach ensures full compatibility with Hyva Checkout while maintaining all original functionality.

### Programmatic Modal Configuration

**Advanced ModalBuilderInterface Implementation**: The HyvaCargus module uses Hyva's ModalBuilderInterface for programmatic modal building, providing enhanced control and accessibility features.

**Key Programmatic Features**:

1. **Custom Dialog Reference**: `withDialogRefName('cargus-map-dialog')` - Provides unique identification for the modal
2. **Positioning Control**: `positionCenter()` - Centers the modal for optimal user experience
3. **Visibility Management**: `initiallyHidden()` + `overlayEnabled()` - Proper modal state control
4. **Accessibility Compliance**: 
   - `withAriaLabel('Select Pickup Point Map')` - Screen reader support
   - `withAriaLabelledby('cargus-map-title')` - Links to modal title
5. **Advanced Styling**:
   - `addOverlayClass('bg-black', 'bg-opacity-50')` - Custom overlay styling
   - `addContainerClass('flex', 'items-center', 'justify-center', 'min-h-screen', 'p-4')` - Container layout
   - `withDialogClasses(...)` - Comprehensive dialog styling with Tailwind CSS
6. **Focus Management**: `excludeSelectorsFromFocusTrapping('.leaflet-control-container')` - Prevents focus issues with map controls
7. **Content Integration**: Block-based content rendering with proper data passing

**Benefits of Programmatic Approach**:
- Enhanced accessibility with proper ARIA labels and focus management
- Better integration with Hyva's modal system architecture
- More precise control over modal behavior and appearance
- Improved maintainability with explicit configuration
- Future-proof implementation following Hyva's recommended patterns

## Troubleshooting

### Common Issues

1. **Inline modal not opening**: Ensure the Magewire component is properly loaded and `showMap` property is reactive
2. **Map not loading in modal**: Ensure the original CargusShipGo module is properly configured and enabled
3. **Pudo points not appearing**: Check that `window.checkoutConfig.cargus.pins` contains data
4. **Selection not saving**: Verify that the Magewire component is properly registered in the layout
5. **Modal not closing after selection**: Check that the `selectedPudo` function properly calls the Magewire methods
6. **Validation errors**: Ensure the shipping method codes match in `isCargusShippingMethod()`

### Debug Mode

Enable Magento developer mode and check:
- Browser console for JavaScript errors
- Magento logs for PHP errors
- Network tab for failed AJAX requests

## Support

For issues related to:
- **This compatibility module**: Contact Liquidlab Agency
- **Original CargusShipGo functionality**: Contact Urgent
- **Hyva Checkout**: Contact Hyva Themes

## License

Proprietary - Liquidlab Agency

## Changelog

### Version 1.8.0
- **Hyva Modal Event Listeners Fix**: Resolved issue where `document.addEventListener` was not working properly with Hyva modal events
- **Proper Alpine.js Integration**: Replaced incorrect `document.addEventListener('hyva-modal-show')` with proper Alpine.js event handling
- **Removed Workarounds**: Eliminated MutationObserver and DOMContentLoaded workarounds that were needed due to incorrect event handling
- **Dialog-Specific Events**: Added proper dialog-specific event handling using `event.detail.dialog === 'cargus-map-dialog'`
- **Simplified Architecture**: Streamlined event handling to follow Hyva's recommended patterns
- **Better Integration**: Improved compatibility with Hyva's modal system architecture

### Version 1.7.0
- **Production Code Cleanup**: Removed all debugger statements from modal template for production readiness
- **File Optimization**: Reduced modal template from 480 to 476 lines by removing 4 debugger statements
- **Code Quality**: Eliminated debugging code while preserving all essential functionality
- **Verification**: Comprehensive testing confirms all modal features remain intact
- **Performance**: Cleaner code execution without debugging interruptions

### Version 1.6.0
- **Modal Initialization Fix**: Resolved issue where `cargus_modal_content` was never populated because `initializeCargusMapModal` was not triggered on modal open
- **Enhanced Event Detection**: Updated event listener to use `document.getElementById` instead of `event.target.querySelector` for more reliable element detection
- **Multiple Trigger Mechanisms**: Added Alpine.js initialization event listener and MutationObserver as fallback mechanisms
- **Visibility Detection**: Implemented element visibility check (`offsetParent !== null`) to ensure modal is actually shown before initialization
- **Robust Modal System**: Added multiple trigger mechanisms to ensure reliable modal initialization across different scenarios
- **Duplicate Prevention**: Enhanced `data-map-initialized` attribute system to prevent duplicate initialization attempts

### Version 1.5.0
- **Modal Data Passing Fix**: Resolved issue where `->setData('magewire', $magewire)` was not working in modal content template
- **Global Data Generation**: Moved `window.cargusData` generation from modal content template to main pudo-point.phtml template
- **Template Independence**: Modal content template no longer depends on `$magewire` variable, improving reliability
- **Magewire Component Reference**: Updated Magewire.find() call to use static component ID instead of dynamic `$magewire->id`
- **Enhanced Stability**: Eliminated escaped HTML content issues in modal rendering
- **Preserved Functionality**: All map features and JavaScript functionality maintained with global data access

### Version 1.4.0
- **Programmatic Modal Building**: Implemented advanced ModalBuilderInterface for comprehensive modal configuration
- **Enhanced Accessibility**: Added proper ARIA labels (`withAriaLabel`, `withAriaLabelledby`) for screen reader support
- **Advanced Positioning**: Configured `positionCenter()` for optimal modal placement and user experience
- **Focus Management**: Implemented `excludeSelectorsFromFocusTrapping()` to prevent focus issues with Leaflet map controls
- **Custom Dialog Reference**: Added `withDialogRefName('cargus-map-dialog')` for better modal identification
- **Visibility Control**: Configured `initiallyHidden()` and `overlayEnabled()` for proper modal state management
- **Advanced Styling**: Implemented programmatic styling with `addOverlayClass`, `addContainerClass`, and `withDialogClasses`
- **Improved Integration**: Better integration with Hyva's modal system architecture following recommended patterns
- **Enhanced Maintainability**: Explicit configuration makes the modal system more maintainable and extensible

### Version 1.3.0
- **Proper Hyva Modal Implementation**: Updated modal creation to follow Hyva's recommended patterns
- **Block-Based Content Rendering**: Replaced `ob_start()` and `include` with proper Magento block system
- **Performance Improvement**: Eliminated output buffering for better performance
- **Standards Compliance**: Now consistent with other Hyva modal implementations
- **Maintainability Enhancement**: Cleaner, more extensible code following Magento best practices

### Version 1.2.0
- **Hyva Modal System Integration**: Refactored to use Hyva's native modal system instead of inline modal implementation
- **Modal ViewModel Integration**: Added proper integration with Hyva's Modal ViewModel for consistent modal behavior
- **Separated Modal Content**: Extracted modal content to separate template (`modal/map-content.phtml`) for better organization
- **Simplified Magewire Component**: Removed `showMap` property and related methods, focusing on core pudo point functionality
- **Enhanced Architecture**: Improved separation of concerns with dedicated modal content template
- **Better Maintainability**: Cleaner code structure following Hyva's recommended patterns
- **Preserved Functionality**: Maintained all map features while improving the underlying architecture

### Version 1.1.0
- **Config Provider Fix**: Resolved compatibility issue with Hyva Checkout's lack of config provider support
- **Direct ConfigProvider Injection**: Added injection of original CargusShipGo ConfigProvider into Magewire component
- **Server-Side Data Generation**: Implemented `getCargusData()` method for retrieving configuration data
- **Template Updates**: Replaced all `window.checkoutConfig.cargus` references with PHP-generated data
- **Enhanced Compatibility**: Ensured full compatibility with Hyva Checkout architecture
- **Maintained Functionality**: Preserved all original map features (icons, pins, search, selection)

### Version 1.0.0
- Initial release
- Full Hyva Checkout compatibility
- Inline modal map for pudo point selection (no separate popup window)
- Magewire reactive state management for map visibility
- Validation and error handling
- Mobile-responsive design with Tailwind CSS
- Simplified architecture without controllers or separate routes