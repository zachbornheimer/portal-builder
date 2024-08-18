# Plugin name
PLUGIN_NAME := portal-builder

# Directories
BUILD_DIR := build
DIST_DIR := dist

# Exclude files based on .gitignore and .gitattributes
EXCLUDES := $(shell cat .gitignore .gitattributes | grep export-ignore | awk '{print "--exclude=" $$1}')

# Default target
all: build

# Clean build and dist directories
clean:
	rm -rf $(BUILD_DIR) $(DIST_DIR)

# Create necessary directories
setup:
	mkdir -p $(BUILD_DIR) $(DIST_DIR)

# Build the plugin
build: clean setup
	rsync -av --exclude-from=.gitignore --exclude-from=.gitattributes . $(BUILD_DIR)/$(PLUGIN_NAME)
	cd $(BUILD_DIR) && zip -r ../$(DIST_DIR)/$(PLUGIN_NAME).zip $(PLUGIN_NAME)

# Install composer dependencies (for dev)
install-dev:
	composer install

# Install composer dependencies (for production)
install-prod:
	composer install --no-dev --optimize-autoloader

# Run build for production
release: install-prod build

.PHONY: all clean setup build install-dev install-prod release
