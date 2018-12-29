
versions_contents="{\n"

while IFS= read -r -d "|" VERSION; do
  echo "$VERSION"
  versions_contents="${versions_contents}  \"${VERSION}\": {\"path\": \"/download/${VERSION}/mysql-helper\"}\n"
done <<< $(find "public-web/download" -maxdepth 1 -mindepth 1 -printf '%f|')

versions_contents="${versions_contents}}"

echo -e "${versions_contents}" > "public-web/versions"
