# 🔖 Puzzling Price Changer

[![WordPress Plugin](https://img.shields.io/badge/WordPress-Tested%206.4-blue)](https://wordpress.org)
[![WooCommerce Compatible](https://img.shields.io/badge/WooCommerce-Compatible-green)](https://woocommerce.com)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

A powerful WordPress plugin for managing and bulk modifying WooCommerce product prices with ease. 💰

## 🎯 Features

- 📊 **Price Management Dashboard** - Intuitive interface to view and manage all your product prices
- 🚀 **Bulk Price Updates** - Change prices for multiple products at once
- 📈 **Price Bump Module** - Sophisticated bulk price modification with queue management
- 📁 **CSV Import** - Batch import prices from CSV files
- 🔄 **Background Processing** - Handle large operations without timeout issues
- 📝 **Operation History** - Track and log all price changes
- ⚙️ **Nonce Protection** - Built-in security for all operations
- 🌐 **Multi-language Support** - Full i18n support for translations
- ⏸️ **Queue Management** - Pause, resume, and cancel bulk operations
- 📋 **Detailed Logs** - Comprehensive logging for all operations

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **WooCommerce**: 5.0 or higher (tested up to 8.5)
- **PHP**: 7.4 or higher

## 🚀 Installation

### From GitHub

1. Clone the repository into your `wp-content/plugins/` directory:
```bash
cd wp-content/plugins/
git clone https://github.com/arsalanarghavan/puzzling-price-changer.git
cd puzzling-price-changer
```

2. Activate the plugin:
   - Go to **WordPress Admin Dashboard**
   - Navigate to **Plugins**
   - Find "تغییر قیمت‌ها" (Puzzling Price Changer)
   - Click **Activate**

### Manual Installation

1. Download the plugin as a ZIP file
2. Extract it to `wp-content/plugins/puzzling-price-changer/`
3. Activate from the WordPress Plugins page

## 💡 Usage

### Price Management

1. Navigate to **Products** → **Price Manager** in the WordPress admin
2. View all your products with their current prices
3. Update prices individually or in bulk
4. Monitor changes in real-time

### Bulk Price Changes

1. Go to **Products** → **Bulk Price Bump**
2. Configure your price modification strategy:
   - Fixed amount increase/decrease
   - Percentage-based changes
   - Specific price targets
3. Select products to modify
4. Start the operation and monitor progress

### CSV Import

1. Prepare a CSV file with product IDs and new prices:
```csv
product_id,new_price
123,99.99
456,149.99
789,199.99
```

2. Go to **Products** → **Bulk Price Bump**
3. Select **Import CSV**
4. Upload your CSV file
5. Confirm and process

## 📁 Project Structure

```
puzzling-price-changer/
├── my-price-manager.php          # Main plugin file and dashboard
├── woo-bulk-price-bump.php       # Bulk price modification module
├── assets/
│   ├── css/
│   │   └── price-manager.css     # Styling for admin pages
│   └── js/
│       └── price-manager.js      # Admin page functionality
├── languages/                    # Translation files
└── README.md                     # This file
```

## 🔧 Key Modules

### Price Manager (`my-price-manager.php`)
- Main plugin initialization
- Admin dashboard and settings
- Product price display and management
- Integration with WordPress admin menu

### Bulk Price Bump (`woo-bulk-price-bump.php`)
- Queue-based bulk price modifications
- CSV import functionality
- Background job processing
- Operation logging and history
- Lock mechanism for concurrent operation prevention

## 🛡️ Security

- ✅ Nonce verification on all forms
- ✅ Capability checks (manage_woocommerce)
- ✅ Input sanitization and validation
- ✅ SQL injection prevention via WP WPDB
- ✅ Operation locking to prevent concurrent modifications

## ⚙️ Configuration

### Hooks and Filters

The plugin provides several hooks for customization:

```php
// Modify price before saving
apply_filters('ppc_before_save_price', $price, $product_id);

// Hook into bulk operations
do_action('ppc_bulk_operation_start', $operation_id);
do_action('ppc_bulk_operation_complete', $operation_id);
```

### Constants

Key plugin constants are defined for easy customization:

```php
const SLUG        = 'xx-bulk-price-bump';
const GROUP       = 'xx-bpb';
const PER_PAGE    = 100;
const LOG_DIR     = 'xx-bpb-logs';
```

## 📝 Changelog

### Version 2.4.0
- Enhanced bulk price modification system
- Improved CSV import functionality
- Better error handling and user feedback
- Optimized database queries
- Enhanced logging capabilities

## 🤝 Contributing

We welcome contributions! To contribute:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This plugin is licensed under the GNU General Public License v2 or later. See the [LICENSE](LICENSE) file for details.

## 👨‍💻 Author

**Arsalan Arghavan**
- GitHub: [@arsalanarghavan](https://github.com/arsalanarghavan)
- Website: [arsalanarghavan.ir](https://arsalanarghavan.ir)

## 🆘 Support

For issues, feature requests, or questions:

1. Check existing [Issues](https://github.com/arsalanarghavan/puzzling-price-changer/issues)
2. Create a new issue with detailed description
3. Include error logs and steps to reproduce

## 🐛 Troubleshooting

### Plugin Not Showing in Dashboard
- Ensure WooCommerce is installed and activated
- Check your WordPress version (5.0+)
- Verify PHP version (7.4+)

### Bulk Operations Timing Out
- The plugin uses background jobs - operations are queued and processed
- Check logs in the WordPress uploads directory for detailed information
- Reduce `PER_PAGE` constant if needed

### CSV Import Errors
- Verify CSV file format (comma-separated)
- Check that product IDs are valid
- Ensure prices are numeric values

## 🎯 Roadmap

- [ ] REST API support for external integrations
- [ ] Advanced scheduling for automatic price updates
- [ ] Price history and analytics dashboard
- [ ] Integration with supplier price feeds
- [ ] Mobile app support
- [ ] Multi-language admin interface

## ⭐ Show Your Support

If this plugin helped you, please star it on GitHub! ⭐

---

**Made with ❤️ by Arsalan Arghavan**

*Last Updated: December 2025*
