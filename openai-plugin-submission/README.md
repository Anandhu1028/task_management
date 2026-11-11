# TaskFlow Task Management Assistant — ChatGPT Submission Package

**Developer:** Focuz Academy (Anandhu1028)
**Production URL:** https://tracker-manage.focuzacademy.in
**MCP Endpoint:** https://tracker-manage.focuzacademy.in/mcp
**Support Email:** support@focuzacademy.in
**Package Created:** November 2026

---

## Overview

TaskFlow is a multi-tenant task management and operational reporting platform. This package contains the configuration required to set up the TaskFlow MCP connector in ChatGPT, so any authenticated TaskFlow user can connect their account and interact with their authorized tasks, reports, and work updates through natural language.

### What Users Can Do

- View their assigned tasks, overdue tasks, and task details
- Search tasks by keyword, status, priority, or project
- Get team workload reports, project progress, and performance analytics
- Create tasks and submit daily work updates
- All scoped to the user's role — employees see only their tasks, team leaders see their department, CEOs see company-wide data

---

## Package Contents

```
openai-plugin-submission/
├── README.md                        ← This file
├── SUBMISSION_CHECKLIST.md          ← OpenAI requirements checklist
├── oauth-configuration.json         ← OAuth 2.1 public configuration
├── mcp-server-info.json             ← MCP server metadata and tool list
├── openapi-spec.json                ← OpenAPI 3.0.3 specification
├── reviewer-testing-guide.md        ← Step-by-step reviewer instructions
├── assets/
│   ├── logo.svg                     ← App logo (SVG, 512×512 viewBox)
│   └── logo.png                     ← App logo (PNG, 512×512)
└── legal/
    ├── privacy-policy.md            ← Privacy policy content
    ├── terms-of-service.md          ← Terms of service content
    ├── support.md                   ← Support contact & FAQ
    └── data-deletion.md             ← Data deletion & revocation guide
```

> **Note:** The actual ChatGPT connector submission is completed through the ChatGPT builder UI — not by uploading this ZIP. This package provides all values needed to fill in the submission form.

---

## ChatGPT Connector Setup Instructions

### Step 1 — GPT Builder or Connector Setup

Go to [https://chatgpt.com/gpts/editor](https://chatgpt.com/gpts/editor) or use the ChatGPT connector submission portal.

### Step 2 — App Information

| Field | Value |
|---|---|
| **App / GPT Name** | TaskFlow Task Management Assistant |
| **Description** | Manage tasks, track team progress, view overdue items, generate reports, and submit daily work updates — all powered by your TaskFlow account with secure OAuth 2.1 login. |
| **Logo** | Upload `assets/logo.png` (512×512 PNG) |
| **Website** | https://tracker-manage.focuzacademy.in |
| **Privacy Policy URL** | https://tracker-manage.focuzacademy.in/privacy-policy |
| **Terms of Service URL** | https://tracker-manage.focuzacademy.in/terms |
| **Support URL** | https://tracker-manage.focuzacademy.in/support |
| **Data Deletion URL** | https://tracker-manage.focuzacademy.in/data-deletion |
| **Contact Email** | support@focuzacademy.in |

### Step 3 — MCP Server Configuration

| Field | Value |
|---|---|
| **MCP Endpoint URL** | `https://tracker-manage.focuzacademy.in/mcp` |
| **Transport** | Streamable HTTP |
| **Protocol Version** | 2024-11-05 |

### Step 4 — OAuth 2.1 Configuration

| Field | Value |
|---|---|
| **Client ID** | `chatgpt_connector_live` |
| **Client Secret** | *(none — public client, PKCE only)* |
| **Authorization URL** | `https://tracker-manage.focuzacademy.in/oauth/authorize` |
| **Token URL** | `https://tracker-manage.focuzacademy.in/oauth/token` |
| **Revocation URL** | `https://tracker-manage.focuzacademy.in/oauth/revoke` |
| **Scopes** | `tasks:read reports:read offline_access` |
| **PKCE** | Required — S256 method only |
| **Token Type** | Bearer (HMAC-SHA256 JWT) |
| **Access Token TTL** | 8 hours (28,800 seconds) |
| **Refresh Token TTL** | 30 days (2,592,000 seconds) |

### Step 5 — Discovery Endpoints (Auto-configuration)

The server supports RFC 8414 / RFC 9728 auto-discovery:

| Endpoint | URL |
|---|---|
| Authorization Server Metadata | https://tracker-manage.focuzacademy.in/.well-known/oauth-authorization-server |
| Protected Resource Metadata | https://tracker-manage.focuzacademy.in/.well-known/oauth-protected-resource |
| OpenID Configuration | https://tracker-manage.focuzacademy.in/.well-known/openid-configuration |
| OpenAPI Spec | https://tracker-manage.focuzacademy.in/api/mcp/openapi.json |

### Step 6 — System Prompt (Recommended)

```
You are a TaskFlow Task Management Assistant. You help users manage their tasks, track team progress, generate performance reports, and submit work updates using their TaskFlow account.

Always use the authenticated user's own account scope — never attempt to access tasks or records outside the user's authorized company or role permissions.

When a user asks about tasks, reports, or team activity, use the available MCP tools to fetch live data from their TaskFlow account.
```

---

## Security Architecture

- **Multi-tenant isolation:** Every query is scoped to the authenticated user's company. Cross-company data access is impossible at the database layer.
- **Role-based access:** Employees see only their tasks. Team Leaders see their department. CEO/Admin see company-wide data.
- **PKCE S256 required:** Plain method is rejected in production.
- **Token rotation:** Refresh tokens rotate on every use. Replay of a used token invalidates the entire token lineage.
- **Rate limiting:** Token endpoint limited to 30 requests/minute. MCP endpoint throttled per user.
- **Audit logging:** Every MCP tool call is logged with user ID, company ID, tool name, and timestamp (no tokens logged).

---

## Registered Callback URIs

The following redirect URIs are pre-registered for the `chatgpt_connector_live` client:

```
https://chatgpt.com/connector/oauth/callback
https://chatgpt.com/connector/oauth/yHyws2Of_8Kj
https://chatgpt.com/aip/g-connector/oauth/callback
https://chat.openai.com/aip/g-connector/oauth/callback
```

> If OpenAI provides a new callback URI during submission, contact support@focuzacademy.in to register it.

---

## Reviewer Test Account

> **Action Required:** Before submission, create a reviewer test account with limited Employee-level access and provide credentials separately (never in this package). See `reviewer-testing-guide.md` for the complete test flow.

---

*© 2026 TaskFlow / Focuz Academy. All rights reserved.*

