locals {
  # D4: every resource name starts with this; the table is exactly this.
  name = "expense-tracker-${var.environment}"

  # D15, owner decision 8: the cost ceiling of the on-demand table. Reads beyond it are throttled (the application maps
  # that to 503 + Retry-After) instead of billing without limit.
  dynamodb_max_read_request_units  = 400
  dynamodb_max_write_request_units = 100

  # D25: the one browser origin of the application. The API (APP_ORIGINS) and the receipts bucket's CORS rule both take it.
  # It reads the distribution's domain, and the distribution reads the API's endpoint; that is no cycle, because the
  # endpoint belongs to the API resource and not to the Lambda that receives this value.
  allowed_origin = "https://${module.edge.domain_name}"
}
