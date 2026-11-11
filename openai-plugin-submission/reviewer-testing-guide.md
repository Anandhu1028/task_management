# Reviewer Testing Guide

**App:** TaskFlow Task Management Assistant
**MCP Endpoint:** https://tracker-manage.focuzacademy.in/mcp
**OAuth Authorize:** https://tracker-manage.focuzacademy.in/oauth/authorize

---

## Prerequisites

1. A TaskFlow reviewer test account (Employee-level access). Credentials provided separately.
2. Access to ChatGPT (Pro or above) with connector/MCP support enabled.
3. A browser with network DevTools for inspecting OAuth redirects if needed.

---

## Phase 1 — Verify OAuth Discovery Endpoints

Before connecting, verify that the metadata endpoints are live and correct.

### 1.1 Authorization Server Metadata

```
GET https://tracker-manage.focuzacademy.in/.well-known/oauth-authorization-server
```

Expected response (200 OK):
```json
{
  "issuer": "https://tracker-manage.focuzacademy.in",
  "authorization_endpoint": "https://tracker-manage.focuzacademy.in/oauth/authorize",
  "token_endpoint": "https://tracker-manage.focuzacademy.in/oauth/token",
  "revocation_endpoint": "https://tracker-manage.focuzacademy.in/oauth/revoke",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "code_challenge_methods_supported": ["S256"]
}
```

### 1.2 Protected Resource Metadata

```
GET https://tracker-manage.focuzacademy.in/.well-known/oauth-protected-resource
```

Expected response (200 OK) includes `resource`, `authorization_servers`, and `scopes_supported`.

### 1.3 MCP Health Check

```
GET https://tracker-manage.focuzacademy.in/mcp
```

Expected response (200 OK) with server info JSON — no authentication required for GET.

---

## Phase 2 — ChatGPT OAuth Connection Flow

1. In ChatGPT, open the TaskFlow connector or GPT.
2. Click **Connect** / **Sign in with TaskFlow**.
3. Verify the browser redirects to: `https://tracker-manage.focuzacademy.in/oauth/authorize`
4. Confirm the URL contains:
   - `response_type=code`
   - `client_id=chatgpt_connector_live`
   - `code_challenge_method=S256`
   - `code_challenge=<43+ char base64url string>`
   - `state=<non-empty string>`
   - `redirect_uri=https://chatgpt.com/...` (one of the registered URIs)
5. Log in with the reviewer test account credentials.
6. Review the consent screen — verify it lists the requested scopes clearly.
7. Click **Authorize**.
8. Verify ChatGPT receives the connection successfully (no error page).

---

## Phase 3 — MCP Tool Tests

After connecting, test the following prompts in ChatGPT. Expected tool calls and responses are shown.

### Test 3.1 — List My Tasks

**Prompt:** "Show me my current tasks"

**Expected tool:** `get_my_tasks`
**Expected response:** A list of tasks assigned to the reviewer test account (may be empty if no tasks assigned — this is correct).

---

### Test 3.2 — Search Tasks

**Prompt:** "Search for tasks related to 'design'"

**Expected tool:** `search_tasks` with `query=design`
**Expected response:** Search results or empty list — no cross-company data.

---

### Test 3.3 — Overdue Tasks

**Prompt:** "What tasks are overdue?"

**Expected tool:** `get_overdue_tasks`
**Expected response:** List of overdue tasks within the reviewer's authorized scope.

---

### Test 3.4 — Task Report

**Prompt:** "Give me a task status summary"

**Expected tool:** `get_task_report`
**Expected response:** Counts by status (pending, in_progress, completed) for the reviewer's scope.

---

### Test 3.5 — Project Report

**Prompt:** "Show project progress"

**Expected tool:** `get_project_report`
**Expected response:** Project breakdown table — scoped to authorized company only.

---

### Test 3.6 — Create a Task (Write Permission)

**Prompt:** "Create a task titled 'Reviewer Test Task' assigned to me, due next week"

**Expected tool:** `create_task`
**Expected:** Task created successfully, or a 403 if the reviewer account does not have `tasks:write` scope authorized.

---

### Test 3.7 — Cross-Tenant Isolation Verification

**Prompt:** "Show me tasks for company ID 1" *(or any company not belonging to the reviewer)*

**Expected:** The system should only return tasks for the reviewer's authorized company. Data from other companies must NOT be returned, regardless of the `company_id` parameter provided.

---

## Phase 4 — Token Revocation

1. In ChatGPT, go to Settings → Connected apps → TaskFlow → **Disconnect**.
2. Verify the OAuth tokens are revoked.
3. Confirm that subsequent MCP requests return `401 Unauthorized`.

Alternatively, test revocation directly:

```bash
curl -X POST https://tracker-manage.focuzacademy.in/oauth/revoke \
  -d "token=<access_or_refresh_token>"
```

Expected: `200 OK` (revocation endpoint always returns 200 per RFC 7009).

---

## Phase 5 — Error Cases to Verify

| Scenario | Expected Result |
|---|---|
| MCP POST with no Authorization header | `401 Unauthorized` |
| MCP POST with expired token | `401 Unauthorized` |
| MCP POST with wrong audience token | `401 Unauthorized` |
| OAuth authorize with unregistered redirect_uri | `400 Bad Request` — redirect rejected |
| OAuth authorize with `code_challenge_method=plain` | `400 Bad Request` — plain PKCE rejected |
| Token request without `code_verifier` | `400 Bad Request` |
| Token request with mismatched `code_verifier` | `400 Bad Request` |
| Reusing an already-used authorization code | `400 Bad Request` — code already consumed |
| Reusing an already-rotated refresh token | `401 Unauthorized` — lineage revoked |

---

## Support During Review

If you encounter any issues during review, contact:

- **Email:** support@focuzacademy.in
- **Support page:** https://tracker-manage.focuzacademy.in/support

Response within 24 business hours (Mon–Fri, 9:00 AM – 6:00 PM IST).

---

*© 2026 TaskFlow / Focuz Academy. All rights reserved.*

