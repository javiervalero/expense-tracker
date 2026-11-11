# D26: what Phase 8 (`make deploy-*` and the smoke test) needs: eight outputs (distribution_id was added in Phase 8 for the
# cache invalidation). The origin-verify secrets are NEVER an output: they stay in the state and in the two resources
# that use them.

output "distribution_domain" {
  description = "Domain of the CloudFront distribution, for example d111111abcdef8.cloudfront.net (the application's address)."
  value       = module.edge.domain_name
}

output "distribution_id" {
  description = "Id of the CloudFront distribution (cache invalidation after a front-end upload)."
  value       = module.edge.distribution_id
}

output "site_bucket" {
  description = "Name of the site bucket (the front-end upload)."
  value       = module.edge.site_bucket
}

output "receipts_bucket" {
  description = "Name of the receipts bucket."
  value       = module.receipts.receipts_bucket
}

output "thumbnails_bucket" {
  description = "Name of the thumbnails bucket."
  value       = module.receipts.thumbnails_bucket
}

output "api_endpoint" {
  description = "Endpoint of the HTTP API (direct calls without the origin-verify header are answered 403 by the application)."
  value       = module.api.api_endpoint
}

output "dlq_url" {
  description = "URL of the receipts dead-letter queue."
  value       = module.receipts.dlq_url
}

output "table_name" {
  description = "Name of the DynamoDB table."
  value       = module.table.table_name
}
