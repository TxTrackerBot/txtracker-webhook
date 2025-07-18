FROM php:8.2-cli
WORKDIR /app
COPY . /app
COPY start.sh /app/start.sh
RUN chmod +x /app/start.sh
CMD ["./start.sh"]

