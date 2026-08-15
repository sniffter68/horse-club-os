# Horse Club OS — MCP access

Horse Club OS exposes a small read-only diagnostic surface through the official WordPress MCP Adapter.

## Requirements

- WordPress 6.9+ (Abilities API is included in core).
- HTTPS on the WordPress site.
- Horse Club OS release built with Composer dependencies included.
- A dedicated WordPress user for the integration is recommended.
- A revocable WordPress Application Password for that user.

On WordPress versions below 6.9 the CRM continues to work normally, but the MCP integration remains disabled.

## MCP endpoint

The official adapter creates the default HTTP endpoint:

`https://YOUR-SITE/wp-json/mcp/mcp-adapter-default-server`

Horse Club OS abilities are published through the default server and discovered through the adapter's built-in ability discovery tools.

## Authentication

Do not use the normal WordPress account password for an MCP client.

Create an Application Password in:

`Users -> Profile -> Application Passwords`

Use a clear name such as `Horse Club OS MCP` and store the generated value in the client secret store/environment. Application Passwords can be revoked independently of the user's normal password.

The WordPress REST API authenticates Application Passwords over HTTPS with HTTP Basic Authentication.

## Recommended account

For development diagnostics, create a dedicated WordPress user instead of using the site owner account.

The abilities still enforce Horse Club OS capabilities:

- `hcos/health-check` — manager/admin-level diagnostics;
- `hcos/inspect-booking` — requires `hcos_view_finances`;
- `hcos/inspect-client-relations` — requires CRM client read capability;
- `hcos/inspect-membership` — requires `hcos_view_finances`.

The current MCP surface is read-only. It cannot create, edit or delete CRM records.

## Local MCP proxy configuration

The official WordPress MCP Adapter documentation supports the `@automattic/mcp-wordpress-remote` proxy for MCP clients that expect a local STDIO server.

Example configuration:

```json
{
  "mcpServers": {
    "horse-club-os": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://YOUR-SITE/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "YOUR_MCP_USERNAME",
        "WP_API_PASSWORD": "YOUR_APPLICATION_PASSWORD"
      }
    }
  }
}
```

Do not commit the username/password pair to Git. Keep secrets in the MCP client's secure configuration or environment.

## First verification

After deployment:

1. Confirm WordPress is 6.9 or newer.
2. Confirm the Horse Club OS release contains `vendor/`.
3. Create the dedicated Application Password.
4. Connect the MCP proxy/client to the endpoint above.
5. Run the adapter's ability discovery tool.
6. Confirm these abilities are visible:
   - `hcos/health-check`
   - `hcos/inspect-booking`
   - `hcos/inspect-client-relations`
   - `hcos/inspect-membership`
7. Execute `hcos/health-check` and verify the returned Horse Club OS version and counters.

## Security rules

- HTTPS only for remote MCP access.
- Never paste the normal wp-admin password into an MCP configuration.
- Prefer a dedicated integration user.
- Grant only the WordPress/Horse Club OS role required for the intended abilities.
- Rotate/revoke the Application Password when a device or integration is retired.
- Do not add write abilities until their permission model and audit trail have automated tests.
