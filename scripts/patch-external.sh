#!/bin/bash

# This script is used to patch external libraries.
# If any patch fails, the script will exit with error code 1.

# Do not exit on failure so that we can print all errors at once.
set +e

# Check if the script is called from the root folder of the project.
if [ ! -f "scripts/patch-external.sh" ]; then
    echo -e "\033[0;33mPlease run this script from the root folder of the project.\033[0m"
    exit 1
fi

# Make sure node_modules is installed.
if [ ! -d "node_modules" ]; then
    echo -e "\033[0;33mPlease run 'npm install' before running this script.\033[0m"
    exit 1
fi

# Apply all patches.
HAS_ERROR=0
for patch in patches/*.patch; do
    echo -e "\n\033[0;32mApplying patch: $patch\033[0m"
    patch -p1 -N < $patch
    if [ $? -ne 0 ]; then
        echo -e "\033[0;33mFailed to apply patch: $patch\033[0m"
        HAS_ERROR=1
    fi
done

# Exit with error code if any patch failed.
# This way we fail the CI build.
if [ $HAS_ERROR -ne 0 ]; then
    echo -e "\n\033[0;31mFailed to apply some patches. See above for details.\033[0m"
    exit 1
else
    echo -e "\n\033[0;32mAll patches applied successfully.\033[0m"
fi