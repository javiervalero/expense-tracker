# The origin-verify secret is carried only by the sensitive outputs `origin_verify_header_value` here and
# `environment_variables` in modules/api; the root never exports it. Never wrap either in nonsensitive().

output "domain_name" {
  description = "Domain of the distribution, for example d111111abcdef8.cloudfront.net; the API's allowed origin and the receipts CORS origin are https:// plus this."
  value       = aws_cloudfront_distribution.this.domain_name
}

output "distribution_id" {
  description = "Id of the distribution (cache invalidation in Phase 8)."
  value       = aws_cloudfront_distribution.this.id
}

output "distribution_arn" {
  description = "ARN of the distribution."
  value       = aws_cloudfront_distribution.this.arn
}

output "site_bucket" {
  description = "Name of the site bucket (the front-end upload in Phase 8)."
  value       = aws_s3_bucket.site.bucket
}

output "site_bucket_arn" {
  description = "ARN of the site bucket."
  value       = aws_s3_bucket.site.arn
}

output "api_origin_domain" {
  description = "Domain of the API origin (the API endpoint without its scheme)."
  value       = local.api_origin_domain
}

output "content_security_policy" {
  description = "The CSP set on the site and assets responses."
  value       = local.content_security_policy
}

output "receipts_host" {
  description = "S3 host of the receipts bucket as it appears in the CSP connect-src."
  value       = local.receipts_host
}

# Read from the distribution's API origin, so a caller's test sees the value CloudFront really sends. Sensitive: it is the
# origin-verify secret; a test compares it as a boolean and never prints it.
output "origin_verify_header_value" {
  description = "Value of the X-Origin-Verify header on the distribution's API origin. Sensitive."
  value = one(flatten([
    for origin in aws_cloudfront_distribution.this.origin : [
      for header in origin.custom_header : header.value if header.name == "X-Origin-Verify"
    ]
  ]))
  sensitive = true
}

output "thumbnails_host" {
  description = "S3 host of the thumbnails bucket as it appears in the CSP img-src."
  value       = local.thumbnails_host
}
