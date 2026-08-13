#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compatibility="${project_root}/config/creator-signal-compatibility.json"
openapi="${project_root}/docs/swagger/openapi-inlined.json"

jq -e '
  .components.securitySchemes.ApiKey.type == "apiKey"
  and .components.securitySchemes.ApiKey.in == "header"
  and .components.securitySchemes.ApiKey.name == "X-Api-Key"
' "${openapi}" >/dev/null

jq -e '
  [
    .paths[]
    | to_entries[]
    | select(.key == "get" or .key == "post" or .key == "put" or .key == "patch" or .key == "delete")
    | .value.parameters[]?
    | select(.name == "apiKey" and .in == "query")
  ]
  | length == 0
' "${openapi}" >/dev/null

jq -e '.paths["/rest/v{version}/short-urls/shorten"].get.security[0].ApiKey == []' "${openapi}" >/dev/null

mapfile -t required_operations < <(jq -r '.requiredOperationIds[]' "${compatibility}")
mapfile -t available_operations < <(jq -r '
  .paths[]
  | to_entries[]
  | select(.key == "get" or .key == "post" or .key == "put" or .key == "patch" or .key == "delete")
  | .value.operationId // empty
' "${openapi}" | sort -u)

for operation in "${required_operations[@]}"; do
  if ! printf '%s\n' "${available_operations[@]}" | grep -Fqx -- "${operation}"; then
    echo "Missing required client operation: ${operation}" >&2
    exit 1
  fi
done

release="$(jq -r '.creatorSignalRelease' "${compatibility}")"
printf 'Creator Signal REST contract %s passed with %d required operations.\n' "${release}" "${#required_operations[@]}"
