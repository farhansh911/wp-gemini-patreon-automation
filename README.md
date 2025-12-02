# Gemini Patreon Control

WordPress plugin that automates episode access management using AI and integrates with Patreon for content monetization.

## 🎯 Features

### 1. **AI-Powered Episode Management**
- Use natural language commands to control episode access
- Powered by Google's Gemini AI
- Examples:
  - "Make episode 5 free for everyone"
  - "Change episode 12 to advance access"
  - "Unlock episode 8"

### 2. **Automatic Episode Number Sync**
- Extracts episode numbers from titles automatically
- Bulk sync tool for existing episodes
- Supports multiple formats (Episode XXX, Ep XXX, Chapter XXX)

### 3. **Scheduled Auto-Unlock**
- Set different unlock schedules per novel/series
- Automatic conversion from advance (paid) to free
- Weekend skipping option (Mon-Fri only publishing)
- Unlocks episodes starting from the oldest first

### 4. **Patreon Integration**
- Automatically updates Patreon post visibility
- Syncs tier requirements
- Works with Patreon WordPress plugin

## 📋 Requirements

- WordPress 5.0+
- PHP 7.4+
- Custom post types: `novels` and `episodes`
- ACF (Advanced Custom Fields) - optional
- [Patreon WordPress Plugin](https://wordpress.org/plugins/patreon-connect/)
- Google Gemini API key
- Patreon Creator Access Token

## 🚀 Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/gemini-patreon-control/`
3. Activate the plugin through WordPress admin
4. Navigate to **Gemini Control** in admin menu

## ⚙️ Setup

### Step 1: Configure API Keys

Go to **Gemini Control → Settings** and configure:

1. **Gemini API Key**
   - Get your key from [Google AI Studio](https://makersuite.google.com/app/apikey)
   - Paste into the "Gemini API Key" field

2. **Patreon Credentials**
   - Creator Access Token
   - Refresh Token
   - Client ID
   - Client Secret
   - Get these from [Patreon Developer Portal](https://www.patreon.com/portal/registration/register-clients)

3. **Patreon Silver Tier ID**
   - Go to Patreon.com → Settings → Tiers
   - Click your $5 tier
   - Copy the ID from the URL

4. **Field Names**
   - Episode Number meta key (default: `episode_number`)
   - Patreon post ID meta key (default: `patreon_post_id`)
   - ACF field type (default: `section`)

### Step 2: Sync Episode Numbers

1. Go to **Gemini Control → Sync Episodes**
2. Click **"Preview"** to see what will be synced
3. Click **"Sync All Episodes"** to auto-fill episode numbers

### Step 3: Configure Auto-Unlock

1. Go to **Gemini Control → Auto Unlock**
2. Click **"Add Novel Schedule"**
3. For each novel:
   - Select the novel
   - Enter search term (text that appears in episode titles)
   - Set unlock interval (e.g., 1 day, 2 days)
   - Set time (24-hour format, e.g., 02:00)
   - Check "Skip Sat/Sun" if needed
4. Click **"Save Schedules"**
5. Test with **"Test (Preview)"** button

## 📖 Usage

### AI Commands

Go to **Gemini Control → AI Commands** and type natural language:

```
Make episode 5 free for everyone
```

The AI will:
1. Find episode 5
2. Change taxonomy from "Advance" to "Free"
3. Update Patreon tier to public
4. Update ACF fields

### Automatic Scheduling

Once configured, episodes will automatically unlock based on your schedule:

**Example 1: Daily Publishing (Mon-Fri)**
- Novel: Surviving The Game as a Barbarian
- Schedule: Every 1 day at 02:00 AM
- Skip weekends: Yes
- Result: One episode unlocks Mon-Fri at 2 AM

**Example 2: Every Other Day**
- Novel: Lee Gwak
- Schedule: Every 2 days at 02:00 AM
- Skip weekends: Yes
- Result: One episode every 2 weekdays

## 🏗️ Technical Details

### Custom Post Types

The plugin expects two custom post types:

**Episodes:**
```php
post_type: 'episodes'
taxonomy: 'chapter-categories' (terms: 'advance', 'free')
meta: 'episode_number' (integer)
meta: 'patreon_post_id' (string)
```

**Novels:**
```php
post_type: 'novels'
Used for grouping episodes
```

### How Auto-Unlock Works

1. **Cron Job**: Runs daily at the earliest scheduled time
2. **Episode Selection**: Finds oldest advance episode by episode number
3. **Weekend Logic**: If "Skip Sat/Sun" is enabled, only counts Mon-Fri
4. **Execution**:
   - Changes taxonomy from "Advance" to "Free"
   - Removes Patreon tier restriction
   - Updates ACF fields
   - Calculates next unlock date

### Episode Matching

Episodes are matched to novels by searching for the novel title (or custom search term) in the episode title:

```
Novel: "Surviving The Game as a Barbarian"
Search Term: "Surviving Game Barbarian"
Matches: "Surviving The Game as a Barbarian Episode 219" ✅
```

## 🔒 Security

**Important Security Notes:**

1. ⚠️ **Never commit API keys** to version control
2. ⚠️ **Store keys securely** - Use environment variables or wp-config.php constants for production
3. ⚠️ **Restrict admin access** - Only administrators can access plugin features
4. ⚠️ **Sanitize inputs** - All user inputs are sanitized and validated
5. ⚠️ **Use nonces** - All forms use WordPress nonces for CSRF protection

**Recommended: Use Environment Variables**

Instead of storing keys in database, use wp-config.php:

```php
define('GEMINI_API_KEY', 'your-key-here');
define('PATREON_ACCESS_TOKEN', 'your-token-here');
```

## 🐛 Troubleshooting

### "Episode not found"
- Check that episode has `episode_number` meta field filled
- Run **Sync Episodes** tool to auto-fill missing numbers
- Verify custom post type is `episodes`

### "Patreon API error"
- Verify your access token is valid
- Check that token hasn't expired
- Ensure you have creator permissions

### "No episodes to unlock"
- Check that episodes have "Advance" taxonomy term
- Verify search term matches episode titles
- Check that episodes exist for the novel

### Wrong timezone
- Plugin uses WordPress timezone from Settings → General
- Verify your timezone is set correctly

## 📝 Changelog

### Version 1.0.0
- Initial release
- AI-powered episode management
- Auto-unlock scheduling
- Patreon integration
- Weekend skipping
- Episode number sync tool

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

This plugin is licensed under GPL v2 or later.

## 👤 Author

Created for automated web novel/light novel publishing workflows.

## 🙏 Credits

- Powered by [Google Gemini AI](https://ai.google.dev/)
- [Patreon API](https://docs.patreon.com/)
- Built for WordPress

## ⚠️ Disclaimer

This plugin is provided "as is" without warranty. Always backup your site before installing new plugins. Test thoroughly on a staging site first.

## 📞 Support

For issues, questions, or feature requests, please open an issue on GitHub.

---

**Note:** This plugin is designed for content creators managing serialized content (web novels, comics, etc.) with tiered Patreon access.