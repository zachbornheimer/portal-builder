# Plugin name
PLUGIN_NAME := portal-builder

# Directories
BUILD_DIR := build
DIST_DIR := dist

# Get version from the plugin file using grep and awk
VERSION := $(shell grep 'Version:\s*\(.*\)' $(PLUGIN_NAME).php | awk '{print $$3}')

# Define output zip name with version
OUTPUT_FILE := $(DIST_DIR)/$(PLUGIN_NAME)-$(VERSION).zip

# Default target
all: build

# Clean build and dist directories
clean:
	composer clear-cache;
	rm -rf $(BUILD_DIR) $(DIST_DIR)

# Create necessary directories
setup:
	mkdir -p $(BUILD_DIR) $(DIST_DIR)

# Ensure git-archive-all is installed
install-git-archive-all:
	@if ! command -v git-archive-all &> /dev/null; then \
		if [ "$(MAKECMDGOALS)" = "install-git-archive-all" ]; then \
			echo "Installing git-archive-all..."; \
		fi; \
		pip install git-archive-all; \
	else \
		if [ "$(MAKECMDGOALS)" = "install-git-archive-all" ]; then \
			echo "git-archive-all is already installed."; \
		fi; \
	fi

# Update submodules to ensure they are on the latest commit
update-submodules:
	git submodule update --init --recursive

# Build the plugin including submodules using git-archive-all
build: clean setup install-prod install-git-archive-all update-submodules
	git-archive-all -9 $(shell find vendor -type f | sed 's/^/--include="/;s/$$/"/') $(OUTPUT_FILE)

# Install composer dependencies (for dev)
install-dev:
	rm -rf vendor
	composer install

# Install composer dependencies (for production)
install-prod:
	rm -rf vendor
	composer install --no-dev --optimize-autoloader

# Run build for production
release: install-prod build

.PHONY: all clean setup build install-git-archive-all update-submodules install-dev install-prod release
