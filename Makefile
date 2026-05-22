.PHONY: generate-types

# Export the API Platform OpenAPI spec and regenerate the PWA TypeScript types.
# Run after any change to an API resource, serialization group, or DTO.
#
# Uses /tmp inside the pwa container (outside the /app volume mount) so the
# intermediate JSON does not pollute the host pwa/ directory.
generate-types:
	docker compose exec -T api php bin/console api:openapi:export --no-interaction > api/public/docs.json
	docker compose cp api/public/docs.json pwa:/tmp/docs.json
	docker compose exec -T pwa npx openapi-typescript /tmp/docs.json -o /app/src/types/api.d.ts
	docker compose exec -T pwa rm -f /tmp/docs.json
	rm -f api/public/docs.json
	@echo "✓ pwa/src/types/api.d.ts regenerated"
