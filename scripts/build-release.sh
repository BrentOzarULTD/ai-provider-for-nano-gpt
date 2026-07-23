#!/usr/bin/env bash

set -euo pipefail

readonly PLUGIN_SLUG="modeltrestle-ai-connector-for-nano-gpt"
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
readonly PLUGIN_FILE="${PROJECT_DIR}/${PLUGIN_SLUG}.php"
readonly DIST_DIR="${PROJECT_DIR}/dist"

if [[ ! -f "${PLUGIN_FILE}" ]]; then
    echo "Plugin bootstrap not found: ${PLUGIN_FILE}" >&2
    exit 1
fi

plugin_version="$(sed -n 's/^ \* Version: \([^[:space:]]*\)$/\1/p' "${PLUGIN_FILE}")"
if [[ ! "${plugin_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Could not read a valid version from ${PLUGIN_FILE}." >&2
    exit 1
fi

requested_version="${1:-${plugin_version}}"
requested_version="${requested_version#v}"
if [[ "${requested_version}" != "${plugin_version}" ]]; then
    echo "Requested version ${requested_version} does not match plugin version ${plugin_version}." >&2
    exit 1
fi

readme_version="$(sed -n 's/^Stable tag: \([^[:space:]]*\)$/\1/p' "${PROJECT_DIR}/readme.txt")"
if [[ "${readme_version}" != "${plugin_version}" ]]; then
    echo "readme.txt stable tag ${readme_version:-<missing>} does not match plugin version ${plugin_version}." >&2
    exit 1
fi

staging_root="$(mktemp -d "${TMPDIR:-/tmp}/${PLUGIN_SLUG}.XXXXXX")"
trap 'rm -rf "${staging_root}"' EXIT

package_dir="${staging_root}/${PLUGIN_SLUG}"
mkdir -p "${package_dir}" "${DIST_DIR}"

cp "${PLUGIN_FILE}" "${PROJECT_DIR}/LICENSE" "${PROJECT_DIR}/readme.txt" "${package_dir}/"
cp -R "${PROJECT_DIR}/src" "${package_dir}/src"
for optional_runtime_dir in assets languages; do
    if [[ -d "${PROJECT_DIR}/${optional_runtime_dir}" ]]; then
        cp -R "${PROJECT_DIR}/${optional_runtime_dir}" "${package_dir}/${optional_runtime_dir}"
    fi
done

archive_path="${DIST_DIR}/${PLUGIN_SLUG}-${plugin_version}.zip"
checksum_path="${archive_path}.sha256"
rm -f "${archive_path}" "${checksum_path}"

(
    cd "${staging_root}"
    zip -q -r "${archive_path}" "${PLUGIN_SLUG}"
)

shasum -a 256 "${archive_path}" > "${checksum_path}"

archive_listing="$(unzip -Z1 "${archive_path}")"
required_files=(
    "${PLUGIN_SLUG}/${PLUGIN_SLUG}.php"
    "${PLUGIN_SLUG}/LICENSE"
    "${PLUGIN_SLUG}/readme.txt"
    "${PLUGIN_SLUG}/src/autoload.php"
)

for required_file in "${required_files[@]}"; do
    if ! grep -Fxq "${required_file}" <<< "${archive_listing}"; then
        echo "Release ZIP is missing ${required_file}." >&2
        exit 1
    fi
done

if grep -Eq '(^|/)(\.env[^/]*|\.git|\.github|\.wordpress-local|composer\.(json|lock)|tests|vendor)(/|$)' <<< "${archive_listing}"; then
    echo "Release ZIP contains a forbidden development or credential path." >&2
    exit 1
fi

echo "Built ${archive_path}"
echo "Checksum: $(cut -d ' ' -f 1 "${checksum_path}")"
