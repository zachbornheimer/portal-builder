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
	rm -rf assets/dist
	rm -rf node_modules

# Create necessary directories
setup:
	mkdir -p $(BUILD_DIR) $(DIST_DIR)
	mkdir -p assets/dist

# Update submodules to ensure they are on the latest commit
update-submodules:
	git submodule update --init --recursive

# Install Node.js dependencies
install-node:
	@if ! command -v node &> /dev/null; then \
		echo "Node.js is not installed. Please install Node.js first."; \
		exit 1; \
	fi
	@if ! command -v npm &> /dev/null; then \
		echo "npm is not installed. Please install npm first."; \
		exit 1; \
	fi
	npm install

# Build Svelte components and assets
build-svelte:
	npm run build

# Build the plugin ZIP (HEAD + submodules + vendor + assets/dist)
build: clean setup install-prod install-node build-svelte update-submodules
	bash scripts/make-release-zip.sh

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

# Development build (includes dev dependencies)
dev: clean setup install-dev install-node build-svelte
	@echo "Development build complete. Run 'npm run dev' for watch mode."

# Build only Svelte components (for development)
svelte: install-node build-svelte
	@echo "Svelte components built successfully."

.PHONY: all clean setup build update-submodules install-dev install-prod release dev install-node build-svelte svelte
