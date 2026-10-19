FROM python:3.12-slim

COPY --from=ghcr.io/astral-sh/uv:0.5 /uv /usr/local/bin/uv

ENV UV_PROJECT_ENVIRONMENT=/opt/venv \
    UV_LINK_MODE=copy \
    PATH="/opt/venv/bin:$PATH"

WORKDIR /app
COPY benchmark/simple-store/requirements.txt ./
RUN uv venv "$UV_PROJECT_ENVIRONMENT" --python /usr/local/bin/python \
    && uv pip install --python "$UV_PROJECT_ENVIRONMENT/bin/python" --no-cache -r requirements.txt

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]
