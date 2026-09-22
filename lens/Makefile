VENV := .venv
PY := $(VENV)/bin

.PHONY: install
install: $(VENV)
	$(PY)/pip install --extra-index-url https://download.pytorch.org/whl/cpu \
		$$($(PY)/python -c "import tomllib;print(' '.join(tomllib.load(open('pyproject.toml','rb'))['project']['dependencies']))")

$(VENV):
	python3 -m venv $(VENV)

.PHONY: lint
lint:
	$(PY)/pylint -j 0 --disable=duplicate-code *.py store models nextcloud process routes index
	$(PY)/flake8 --jobs=auto *.py store models nextcloud process routes index

.PHONY: dev
dev:
	$(PY)/pip install "pylint" "flake8"
