#!/bin/bash
#
cleanup() {
  echo "";
  echo "";
  exit
}

trap cleanup INT TERM

PWD=$(pwd)
ROOT="$( cd "$( dirname "$0" )" && cd ./ && pwd )"

# Standard config.
BUILD="build-src"
BUILD_FILE="mysql-helper"
REPO="git@github.com:hnhdigital-os/mysql-helper.git"
BRANCH="master"
COMPOSER="composer"
TARGET="public-web"
AUTO_COMMIT=1

MODE="tags"

# The mode is missing.
if [ "" != "$1" ]; then
  MODE="$1"
else
  echo "Mode is missing! [stable|**]"
  exit 1
fi

cd "${ROOT}/${TARGET}" && git pull

MODE_TARGET="${TARGET}"

# Non-stable mode is being used.
if [ "${MODE}" != "stable" ]; then
  MODE_TARGET="${TARGET}/${MODE}"
fi

# Disable auto-commit build.
if [ "0" == "$2" ]; then
  AUTO_COMMIT="$2"
fi

# Branch.
if [ "" != "$3" ]; then
  BRANCH="$3"
fi

# Create or update build.
cd "${ROOT}"

if [ ! -d "${BUILD}/.git" ]; then
  git clone "${REPO}" "${BUILD}"
  cd "${ROOT}/${BUILD}"
  git checkout "$BRANCH"
else
  cd "${ROOT}/${BUILD}"
  git checkout "$BRANCH"
  git fetch -p -P
  git pull
fi

git submodule update --remote

mkdir -p "${ROOT}/${BUILD}/builds"

/bin/cp -f "${ROOT}/.env" "${ROOT}/${BUILD}/.env"

touch "${ROOT}/${MODE_TARGET}/latest"

# create latest non-stable version
if [ "stable" != "${MODE}" ]; then
  VERSION=`git log --pretty="%H" -n1 HEAD`

  if [ ! -f "${ROOT}/${MODE_TARGET}/${VERSION}" -o "${VERSION}" != "`cat \"${ROOT}/${MODE_TARGET}/latest\"`" ]; then
    rm -rf "${ROOT}/${MODE_TARGET}/download/"
    mkdir -p "${ROOT}/${MODE_TARGET}/download/${VERSION}/"
    ${COMPOSER} install -q --no-dev && \
    bin/compile ${MODE} ${VERSION} && \
    touch --date="`git log -n1 --pretty=%ci HEAD`" "builds/${BUILD_FILE}" && \
    git reset --hard -q ${VERSION} && \
    echo "${VERSION}" > "${ROOT}/${MODE_TARGET}/latest_new" && \
    mv "builds/${BUILD_FILE}" "${ROOT}/${MODE_TARGET}/download/${VERSION}/${BUILD_FILE}" && \
    mv "${ROOT}/${MODE_TARGET}/latest_new" "${ROOT}/${MODE_TARGET}/latest"

    LATEST_VERSION="${VERSION}"
    LATEST_BUILD="${VERSION}/${BUILD_FILE}"
  fi
fi

# create tagged releases
if [ "stable" == "${MODE}" ]; then
  for VERSION in `git tag`; do
    if [ ! -f "${ROOT}/${MODE_TARGET}/download/${VERSION}/${BUILD_FILE}" ]; then
      mkdir -p "${ROOT}/${MODE_TARGET}/download/${VERSION}/"
      git checkout ${VERSION} -q && \
      ${COMPOSER} install -q --no-dev && \
      bin/compile stable ${VERSION} && \
      touch --date="`git log -n1 --pretty=%ci ${VERSION}`" "builds/${BUILD_FILE}" && \
      git reset --hard -q ${VERSION} && \
      mv "builds/${BUILD_FILE}" "${ROOT}/${MODE_TARGET}/download/${VERSION}/${BUILD_FILE}"
      echo "${MODE_TARGET}/download/${VERSION}/${BUILD_FILE} has been built"
    fi
  done

  LATEST_VERSION=$(ls "${ROOT}/${MODE_TARGET}/download" | grep -E '^[0-9.]+$' | sort -r -V | head -1)
  LATEST_BUILD="${LATEST_VERSION}/${BUILD_FILE}"
fi

echo "${LATEST_VERSION}" > "${ROOT}/${MODE_TARGET}/latest"

versions_contents="{\n"

while IFS= read -r -d "|" VERSION; do
  versions_contents="${versions_contents}  \"${VERSION}\": {\"path\": \"/download/${VERSION}/mysql-helper\"},\n"
done <<< $(find "${ROOT}/${MODE_TARGET}/download" -maxdepth 1 -mindepth 1 -printf '%f|')

versions_contents="${versions_contents}}"

echo -e "${versions_contents}" > "${ROOT}/${MODE_TARGET}/versions"
sed -i '1h;1!H;$!d;${s/.*//;x};s/\(.*\),/\1 /' "${ROOT}/${MODE_TARGET}/versions"

# empty checksum
CHECKSUM_FILE="${ROOT}/${MODE_TARGET}/checksum"
> "${CHECKSUM_FILE}"

# Create checksum for each file
# Create checksum for each file
find "${ROOT}/${MODE_TARGET}/download" -type f  -print0 |
  while IFS= read -r -d $'\0' FILE; do
    sha256sum "$FILE" >> "${CHECKSUM_FILE}"
  done

sed -i s#${ROOT}/${MODE_TARGET}##g "${CHECKSUM_FILE}"

# Convert to JSON format
TEMP_CHECKSUM_FILE="${ROOT}/${MODE_TARGET}/temp_checksum"

printf "{\n" > "${TEMP_CHECKSUM_FILE}"
awk '{ print "\t\"" $2 "\": " "\"" $1 "\", " }' "${CHECKSUM_FILE}" >> "${TEMP_CHECKSUM_FILE}"
printf "}\n" >> "${TEMP_CHECKSUM_FILE}"
sed -i '1h;1!H;$!d;${s/.*//;x};s/\(.*\),/\1 /' "${TEMP_CHECKSUM_FILE}"
sed -i 's/\/download/download/g' "${TEMP_CHECKSUM_FILE}"

cat "${TEMP_CHECKSUM_FILE}" > "${CHECKSUM_FILE}"

unlink "${TEMP_CHECKSUM_FILE}"

cd "${ROOT}/${TARGET}" && git add . && git commit -m "Added compilied ${VERSION} binary" && git push

cd "${ROOT}" && git add "public-web" && git commit -m "Update ${TARGET} with latest commit" && git push
