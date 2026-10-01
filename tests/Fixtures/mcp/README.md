# MCP specification schemas (test fixtures)

The JSON schemas and examples of the Model Context Protocol specification,
copied unmodified from
[modelcontextprotocol/modelcontextprotocol](https://github.com/modelcontextprotocol/modelcontextprotocol)
at commit `3098fe94caa1b9e0afaaa6d30e040b61d5802471`:

- `2026-07-28/schema.json` and `2026-07-28/examples/` (`schema/2026-07-28/`)
- `2025-11-25/schema.json` (`schema/2025-11-25/`), used for both legacy
  versions Logbook serves (`2025-11-25`, `2025-06-18`)

They are used only by the test suite (`tests/Integration/Http/Mcp*Test.php`,
`tests/Support/McpClient.php`) to check that every MCP response Logbook
sends matches the specification (spec.md §7.28). They are not part of the
application and are not shipped in the Docker image's web root.

Licence: see `LICENSE` (the MCP project's, Apache-2.0, with earlier
contributions under MIT as it describes).
