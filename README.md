# EasyBlog Auto Expire – Joomla 5 Plugin

**EasyBlog Auto Expire** is a Joomla 5 system plugin that automates the process of disabling or archiving EasyBlog posts after a specified number of days.  
It helps administrators keep content fresh and relevant by automatically managing old posts.

---

## 📦 Features
- Up to **9 independent auto-expire rules**.
- Per-rule options:
  - Enable/disable
  - Custom title
  - Days to expire (1–3650 days)
  - Action on expiry:
    - **Disable** – Unpublish the post.
    - **Archive** – Archive the post.
    - **Both** – Disable and archive.
- **Development Mode** for safe testing.
- Works directly with EasyBlog post data.

---

## 📋 Requirements
- **Joomla**: 5.x (may work on 4.x but not officially tested)
- **EasyBlog**: Installed and configured
- **PHP**: 7.4+ (tested up to 8.3)

---

## 🚀 Installation
1. Download the plugin package:  
   `easyblogautoexpire.zip`
2. In Joomla Administrator:  
   **System → Install → Extensions**
3. Upload the ZIP file.
4. Go to **System → Manage → Plugins**.
5. Enable **EasyBlog Auto Expire**.

---

## ⚙️ Configuration

### Basic Settings
- **Development Mode**:
  - **Yes** – Simulate expiry actions without making changes.
  - **No** – Apply changes to posts.

### Expiry Rules (Rule 1 – Rule 9)
Each rule contains:
| Field | Description |
|-------|-------------|
| **Enabled** | Turns the rule on/off. |
| **Rule Title** | A label for your reference. |
| **Days to Expire** | Days after publish date before expiry triggers. |
| **Action** | Disable, Archive, or Both. |

---

## 💡 Example Rule
**Rule 1**:
- **Title:** Promotions – 30 Days
- **Days:** 30
- **Action:** Archive
- **Enabled:** Yes

Meaning: Any post older than 30 days will be archived automatically.

---

## 🛠 How It Works
1. The plugin checks EasyBlog posts against each enabled rule.
2. If the post’s age exceeds the rule’s days-to-expire, the chosen action is applied.
3. In **Development Mode**, the plugin only logs actions—it does not change posts.

---

## ✅ Best Practices
- Test with **Development Mode = Yes** before applying live changes.
- Use different rules for different content types.
- Consider using Joomla’s **Scheduled Tasks** to run checks automatically.

---

## 🆘 Troubleshooting
- **No posts expiring?**  
  - Check the plugin is enabled.  
  - Ensure the rule is enabled and days are correct.  
  - Turn Development Mode off for live changes.
- **Too many posts expired at once?**  
  - Review your days-to-expire values.

---

## 📄 Changelog
**v1.0.1 – August 2025**
- Initial release
- 9 configurable rules
- Development mode
- EasyBlog disable/archive/both actions

---

## 📬 Support
- Website: [https://mamboschools.com](https://mamboschools.com)  
- Email: [info@mamboschools.com](mailto:info@mamboschools.com)
