.DEFAULT_GOAL := help

PLUGIN_ROOT=$(shell cd -P -- '$(shell dirname -- "$0")' && pwd -P)
PROJECT_ROOT=$(PLUGIN_ROOT)/../../..

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'
.PHONY: help

test:
	@$(PROJECT_ROOT)/vendor/bin/phpunit $(test)