start:
	docker compose up -d

stop:
	docker compose down

clean:
	docker compose down -v

logs:
	docker compose logs -f
