FROM python:3.13-alpine
RUN apk add --no-cache git ca-certificates \
    && addgroup -g 568 pluginsync \
    && adduser -D -u 568 -G pluginsync pluginsync
WORKDIR /app
COPY core.py server.py /app/
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
USER 568:568
EXPOSE 8080
CMD ["python", "-I", "-B", "-c", "import sys; sys.path.insert(0, '/app'); from server import main; main()"]
