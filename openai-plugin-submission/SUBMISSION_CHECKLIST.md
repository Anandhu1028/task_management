# OpenAI ChatGPT Connector — Submission Checklist

**App:** TaskFlow Task Management Assistant
**Last updated:** November 2026

---

## MCP Server Requirements

| Requirement | Status | Notes |
|---|---|---|
| Streamable HTTP transport | ✅ Done | `POST https://tracker-manage.focuzacademy.in/mcp` |
| MCP protocol version 2024-11-05 | ✅ Done | Confirmed in `McpServerService::PROTOCOL_VERSION` |
| JSON-RPC 2.0 request/response | ✅ Done | |
| `tools/list` method | ✅ Done | 17 tools registered |
| `tools/call` method | ✅ Done | |
| `initialize` handshake | ✅ Done | |
| Requires authentication (401 without token) | ✅ Done | `mcp.auth` middleware |
| HTTPS only | ✅ Done | Production on TLS |

---

## OAuth 2.1 Requirements

| Requirement | Status | Notes |
|---|---|---|
| Authorization Code Grant | ✅ Done | `GET /oauth/authorize` |
| PKCE S256 required | ✅ Done | `plain` rejected in production |
| State parameter validation | ✅ Done | CSRF protection |
| Exact redirect URI validation | ✅ Done | No wildcard matching |
| Token endpoint | ✅ Done | `POST /oauth/token` |
| Refresh token support | ✅ Done | `offline_access` scope |
| Refresh token rotation | ✅ Done | Replay detection, lineage revocation |
| Token revocation endpoint (RFC 7009) | ✅ Done | `POST /oauth/revoke` |
| Token expiry enforcement | ✅ Done | 8h access / 30d refresh |
| Public client support (no secret) | ✅ Done | `chatgpt_connector_live` |
| Authorization Server Metadata (RFC 8414) | ✅ Done | `/.well-known/oauth-authorization-server` |
| Protected Resource Metadata (RFC 9728) | ✅ Done | `/.well-known/oauth-protected-resource` |
| Issuer validation | ✅ Done | Issuer in JWT matches server URL |
| Scope validation | ✅ Done | Per-tool scope checks |
| Consent screen | ✅ Done | Custom OAuth authorize view |
| Rate limiting on token endpoint | ✅ Done | 30 req/min throttle |

---

## Application Information

| Requirement | Status | Value |
|---|---|---|
| App name | ✅ Done | TaskFlow Task Management Assistant |
| Short description | ✅ Done | See README.md |
| Developer name | ✅ Done | Focuz Academy |
| Logo (512×512 PNG) | ✅ Done | `assets/logo.png` |
| Website URL | ✅ Done | https://tracker-manage.focuzacademy.in |
| Privacy Policy URL (live, accessible) | ✅ Done | https://tracker-manage.focuzacademy.in/privacy-policy |
| Terms of Service URL (live, accessible) | ✅ Done | https://tracker-manage.focuzacademy.in/terms |
| Support URL (live, accessible) | ✅ Done | https://tracker-manage.focuzacademy.in/support |
| Data Deletion URL (live, accessible) | ✅ Done | https://tracker-manage.focuzacademy.in/data-deletion |
| Contact email | ✅ Done | support@focuzacademy.in |

---

## Security & Compliance

| Requirement | Status | Notes |
|---|---|---|
| Multi-tenant company isolation | ✅ Done | All queries scoped by company_id |
| Role-based access control | ✅ Done | 5 roles enforced at every tool |
| No hardcoded user/company IDs | ✅ Done | Resolved from Bearer token |
| No shared admin tokens | ✅ Done | Each user authenticates individually |
| Cross-tenant data leakage prevention | ✅ Done | Object-level auth in every tool |
| Input validation | ✅ Done | Laravel Form Request validation |
| Audit logging (no tokens logged) | ✅ Done | `mcp_audit_logs` table |
| Rate limiting (MCP endpoint) | ✅ Done | Per-user throttle |
| No sensitive data in logs | ✅ Done | Tokens/passwords never logged |

---

## Legal Pages (live on production)

| Page | Status | URL |
|---|---|---|
| Privacy Policy | ✅ Live | https://tracker-manage.focuzacademy.in/privacy-policy |
| Terms of Service | ✅ Live | https://tracker-manage.focuzacademy.in/terms |
| Support | ✅ Live | https://tracker-manage.focuzacademy.in/support |
| Data Deletion | ✅ Live | https://tracker-manage.focuzacademy.in/data-deletion |

---

## Action Items Before Submission

| Item | Status | Action |
|---|---|---|
| Reviewer test account | ⚠️ Pending | Create a limited Employee-level test account and share credentials with OpenAI reviewer (separately, not in this package) |
| Logo quality review | ⚠️ Pending | Review `assets/logo.png` — replace with a high-quality branded PNG if available |
| OpenAI callback URI confirmation | ⚠️ Pending | Confirm the exact callback URI(s) OpenAI assigns at submission time and verify they match the registered URIs in `McpOAuthClientSeeder.php` |
| Production smoke test | ⚠️ Pending | Run the full OAuth + MCP flow against production before submitting (see `reviewer-testing-guide.md`) |
| App Store category selection | ⚠️ Pending | Select appropriate category in ChatGPT builder (e.g., "Productivity", "Business") |

---

## Items Not Required (Obsolete Formats)

| Item | Status | Reason |
|---|---|---|
| `.well-known/ai-plugin.json` | ✅ Not needed | Obsolete ChatGPT plugin format — do NOT create |
| `manifest.json` plugin file | ✅ Not needed | Replaced by OpenAPI spec + OAuth config in connector framework |
| `logo_url` in manifest | ✅ Not needed | Uploaded directly in ChatGPT builder UI |

---

*All 34 MCP tests pass as of November 2026 (McpOAuthTest: 11, McpServerTest: 14, McpMultiTenantSecurityTest: 9).*

