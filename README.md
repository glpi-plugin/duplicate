# GLPI Duplicate Checker Plugin

A powerful GLPI plugin for detecting and managing duplicate inventory items across your GLPI installation. Identifies duplicates by serial number, UUID, inventory number, and item name, then provides tools to merge, delete, or mark false positives.

## Features

- **Automatic duplicate detection** across 6 asset types: Computers, Phones, Printers, Monitors, Network Equipment, Peripherals
- **Multiple match criteria**: serial number, UUID, inventory number (otherserial), and item name
- **Ignore management**: Mark known false positives and exclude them from future scans
- **Smart merging**: Merge duplicate items with field-by-field comparison, preserving linked records and financial data (infocoms)
- **Linked record handling**: Automatically consolidate or selectively keep linked records (tickets, documents, contracts, etc.)
- **Financial data management**: Compare and merge infocom records (procurement data, warranty info, etc.)
- **Agent-managed tracking**: Identify items imported by GLPI Agent
- **Multi-language support**: English and French translations included
- **Optimized performance**: Batch queries reduce database load significantly

## Installation

1. Download or clone the plugin into your GLPI plugins directory:
   ```bash
   cd /path/to/glpi/plugins
   https://github.com/glpi-plugin/duplicate.git
   ```

2. Go to **Setup → Plugins** in GLPI and install the **Duplicate Checker** plugin

3. Configure user permissions in **Setup → Profiles**:
   - Grant **"View duplicates"** permission to allow users to see the plugin
   - Grant **"Merge / Delete"** permission to allow merging and deleting duplicates

## Usage

### Main Page (Scan & List)

1. Navigate to **Tools → Duplicate Checker**
2. Click **Rescan** to scan all asset types for duplicates
3. Results are displayed with:
   - **Match type**: What field triggered the duplicate (Serial, UUID, Inventory #, or Name)
   - **Item A & B**: The two items detected as duplicates
   - **Actions**:
     - **Compare**: View detailed field differences and merge options
     - **Ignore**: Mark as a false positive (excludes from future scans)

4. Use pagination controls to browse results (25, 50, or 100 per page)

### Compare & Merge Page

1. Click **Compare** on any duplicate pair
2. Select the **base record** (the one to keep):
   - All other items will be deleted
   - The base record ID survives
   - The chosen column has a **blue or green outline** showing which item will be kept
   - A **"✓ Kept"** badge appears in the header of the winner column
3. For fields with different values:
   - **Highlighted rows** (yellow) show mismatches
   - Click anywhere in a column cell to select that side's value, or click the radio button directly
4. Financial data (Infocoms):
   - Review procurement, warranty, and value information
   - Select values for each financial field
5. Linked records (Notes, Tickets, Documents, etc.):
   - **Notes**: See all notes from both items; check which ones to keep
   - **Other records**: Choose which records to keep from Item A or B
   - Check "Check all" to keep all records from one side
6. Click **Merge with selected values** to execute the merge

### Merge Actions

Three merge strategies are available:

- **Keep A, delete B**: Keeps Item A as base, deletes Item B
- **Keep B, delete A**: Keeps Item B as base, deletes Item A
- **Merge with selected values**: Custom merge using your field choices

### Ignoring Duplicates

Click **Ignore** on a pair to mark it as a false positive. The pair will no longer appear in scans, but can be un-ignored by deleting the record from the database.

## Permissions

The plugin defines two permission levels:

| Permission | Allows |
|---|---|
| **View duplicates** | See the plugin menu and scan results |
| **Merge / Delete** | Merge, delete, or perform merge operations |

Configure these in **Setup → Profiles → Duplicate Checker tab**.

## Database Schema

The plugin creates one table:

- **glpi_plugin_duplicate_ignored**: Stores pairs marked as non-duplicates
  - `itemtype`: Asset type (Computer, Phone, etc.)
  - `items_id_a`, `items_id_b`: The two items
  - `match_reason`: Why they were flagged (serial, uuid, otherserial, name)

## Performance Notes

The plugin has been optimized for large inventories:

- **Index page**: Batch queries reduce per-page database hits from 200+ to ~20
- **Compare page**: FK lookups are cached per-page; infocom and agent checks are batched
- **Scan operation**: Intermediate arrays are freed after processing to minimize memory usage
- **Pagination**: 25, 50, or 100 items per page to balance usability and load

Typical performance:
- Small inventory (< 1,000 items): < 1 second scan
- Medium inventory (1K – 10K items): 2–5 seconds scan
- Large inventory (> 10K items): 5–30 seconds scan (depending on duplicate density)

## Security

The plugin follows GLPI security practices:

- **Parameterised queries**: All database operations use GLPI's safe `$DB->request()` API
- **Authorization checks**: Every page verifies user permissions before displaying data
- **CSRF protection**: All state-changing actions are protected by GLPI's CSRF token validation
- **Input validation**: Item types, IDs, and reason codes are whitelist-validated
- **XSS prevention**: All user input and database values are HTML-escaped before output

## Translations

The plugin includes full translations for:
- **English (en_GB)**: Complete UI and error messages
- **French (fr_FR)**: Complete French translation

To use translations, ensure your GLPI language setting matches the locale code (e.g., `Français` for French).

## Troubleshooting

### No duplicates detected
- Verify items exist with matching serial numbers, UUIDs, or names
- Check that you have permission to view the plugin
- Try clicking **Rescan** to force a full scan

### Merge fails with "Permission denied"
- Ensure your user profile has **"Merge / Delete"** permission
- Check the plugin is installed and enabled

### Memory exhausted during scan
- This is rare with the current optimizations, but can occur with very large inventories (> 100K items)
- Limit the number of items scanned by creating separate GLPI instances for different asset types

### French translations not appearing
- Ensure your GLPI language is set to **Français** (Setup → General → Default language)
- Clear your browser cache

## Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Submit a pull request with a clear description of changes

## License

This plugin is provided as-is for GLPI installations. Modify as needed for your environment.

## Support

For issues or questions:
- Check the troubleshooting section above
- Review GLPI plugin documentation: https://docs.glpi-project.org/
- Check GLPI logs: `var/log/glpi.log`

## Changelog

### v1.2.0
- **Clickable diff rows**: Click anywhere in a value cell to select that side — no need to aim at the radio button
- **Instructions modal**: "How to resolve this duplicate" is now a modal dialog instead of an inline collapsible

### v1.1.0
- **Notes visibility fix**: Notes tab now appears for all users with read access, not just admin
- **Visual winner indicator**: Comparison table winner column now has an outline border and "✓ Kept" badge
- **Version-safe notepad table**: Uses GLPI's Notepad::getTable() instead of hardcoded table name

### v1.0.0
- Initial release
- Duplicate detection by serial, UUID, inventory number, name
- Merge with field-by-field selection
- Linked record consolidation
- Financial data merging
- Ignore management
- English & French translations
- Performance optimizations (batch queries, memory management)
