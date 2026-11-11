# Data Deletion & OAuth Revocation

**Live URL:** https://tracker-manage.focuzacademy.in/data-deletion

---

## 1. How to Revoke ChatGPT Access

You have the right to revoke ChatGPT's access to your TaskFlow account at any time. When revoked, all active access tokens and refresh tokens associated with that session are immediately invalidated.

**Option A — In ChatGPT:**
Navigate to ChatGPT workspace settings → Connected apps / Connected accounts → Locate **TaskFlow** → Click **Disconnect**.

**Option B — Via API Endpoint (RFC 7009):**
Send a POST request to `https://tracker-manage.focuzacademy.in/oauth/revoke` with parameter `token=<your_token>`.

**Option C — Manual Administrative Revocation:**
Email support@focuzacademy.in with subject *"Revoke ChatGPT MCP Access"* and your account email. All tokens will be revoked within 24 hours.

---

## 2. How to Request Account & Data Deletion

To request permanent deletion of your individual user account and personal identifiers:

1. Email privacy@focuzacademy.in from your registered TaskFlow account email.
2. Subject line: **"Data Deletion Request - [Your Full Name]"**
3. Include your organization name and whether you wish to delete your individual profile only or request full organizational data purging (requires company Administrator approval).

---

## 3. Data Deletion Timeline

| Action | Timeline |
|---|---|
| OAuth tokens revoked, account set inactive | Within 24 hours |
| Personal profile, credentials, contact info deleted | Within 14 days |
| Company operational records (anonymized audit trails) | Retained per employer requirements |
| Database backup overwrite | 30-day backup cycle |

---

## 4. Confirmation

You will receive written email confirmation once your deletion request has been fulfilled.

**Contact:** privacy@focuzacademy.in

---

*© 2026 TaskFlow / Focuz Academy. All rights reserved.*

