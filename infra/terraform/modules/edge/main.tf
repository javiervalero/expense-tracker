# The edge: the private site bucket, the CloudFront distribution in front of it and of the API, and the response-headers
# policies (Phase 7 plan D4, D7, D17, D18, D19 and D25; ADR 0005, 0008 and 0012).
#   - One origin is the site bucket, reached only through an origin access control (the bucket policy lets this
#     distribution read objects and nobody else). The other is the HTTP API, https only, with the secret X-Origin-Verify
#     header that PHP checks (a direct call to the execute-api address has no such header and gets 403).
#   - /api/* never caches and forwards cookies, query strings and a whitelist of five headers (none with an underscore);
#     /assets/* is cached for a year (content-hashed file names); everything else is the SPA, whose deep links the viewer
#     request function sends to /index.html. There is NO custom_error_response: it would replace API problem responses.
#   - The CSP is built here, from the bucket names and the region, in the host form the SDK signs for (Step 0 R4).
#
# ORDERING (D25). This module reads the API endpoint and bucket NAMES; it produces the distribution domain that the API
# module (APP_ORIGINS) and the receipts module (CORS rule) take as their allowed origin. The bucket policy of the site
# bucket is a separate resource after the distribution (it needs the distribution ARN, and the distribution needs the
# bucket's domain). Never make the API endpoint or the receipt bucket names depend on anything that reads domain_name.

locals {
  site_bucket_name = "${var.name}-site-${var.bucket_suffix}"

  # Managed cache policies (Step 0 R10): constants, no data source (D2).
  caching_disabled_policy_id  = "4135ea2d-6df8-44a3-9df3-4b5a84be39ad"
  caching_optimized_policy_id = "658327ea-f89d-4fab-a63d-7e88639e58f6"

  site_origin_id = "site"
  api_origin_id  = "api"

  # The API origin domain is the endpoint without its scheme (the variable's validation allows https:// only).
  api_origin_domain = trimprefix(var.api_endpoint, "https://")

  # Step 0 R4: us-east-1 signs for the global form, every other region for the regional one. Both are virtual-hosted
  # style, which is why the bucket names have no dots.
  s3_host_suffix  = var.aws_region == "us-east-1" ? "s3.amazonaws.com" : "s3.${var.aws_region}.amazonaws.com"
  receipts_host   = "${var.receipts_bucket}.${local.s3_host_suffix}"
  thumbnails_host = "${var.thumbnails_bucket}.${local.s3_host_suffix}"

  # The ADR 0008 target, plus connect-src (the upload is an XHR) and img-src (thumbnails) for the receipt buckets only.
  # CloudFront accepts at most 1,783 characters; a test pins the limit.
  content_security_policy = join("; ", [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self' https://${local.thumbnails_host}",
    "connect-src 'self' https://${local.receipts_host}",
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ])

  # One headers policy per kind of response. The security block is the same for all (D18); only the CSP and the
  # Cache-Control differ. null = the policy does not set that header (the API keeps PHP's no-store).
  headers_policies = {
    site = {
      content_security_policy = local.content_security_policy
      cache_control           = "no-cache"
    }
    assets = {
      content_security_policy = local.content_security_policy
      cache_control           = "public, max-age=31536000, immutable"
    }
    api = {
      content_security_policy = null
      cache_control           = null
    }
  }

  hsts_max_age_seconds = 63072000 # 2 years

  # Exactly these five (D17). A header with an underscore would be ambiguous between CloudFront, API Gateway and PHP
  # (X_Origin_Verify vs X-Origin-Verify), and the secret and the client address are set by CloudFront or the origin
  # request, never taken from the viewer.
  api_forwarded_headers = ["Origin", "X-Requested-With", "Content-Type", "Accept", "CloudFront-Viewer-Address"]
}

# ---------------------------------------------------------------- site bucket

resource "aws_s3_bucket" "site" {
  bucket        = local.site_bucket_name
  force_destroy = true # dev: Phase 8 destroys the environment, objects included (owner decision 5)
}

resource "aws_s3_bucket_versioning" "site" {
  bucket = aws_s3_bucket.site.id

  versioning_configuration {
    status = "Disabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "site" {
  bucket = aws_s3_bucket.site.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "site" {
  bucket = aws_s3_bucket.site.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "site" {
  bucket = aws_s3_bucket.site.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "site" {
  bucket = aws_s3_bucket.site.id

  rule {
    id     = "abort-incomplete-multipart-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

# Only this distribution may read objects (OAC: the service principal, conditioned on the distribution's ARN), and plain
# HTTP is refused for everyone. No s3:ListBucket: a missing object is answered 403 by S3, and the plan has no custom
# error response to turn that into anything else.
resource "aws_s3_bucket_policy" "site" {
  bucket = aws_s3_bucket.site.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "AllowCloudFrontServicePrincipalReadOnly"
        Effect    = "Allow"
        Principal = { Service = "cloudfront.amazonaws.com" }
        Action    = "s3:GetObject"
        Resource  = "${aws_s3_bucket.site.arn}/*"
        Condition = { StringEquals = { "AWS:SourceArn" = aws_cloudfront_distribution.this.arn } }
      },
      {
        Sid       = "DenyInsecureTransport"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource  = [aws_s3_bucket.site.arn, "${aws_s3_bucket.site.arn}/*"]
        Condition = { Bool = { "aws:SecureTransport" = "false" } }
      }
    ]
  })

  # S3 rejects a policy update that runs concurrently with a public access block update.
  depends_on = [aws_s3_bucket_public_access_block.site]
}

# ---------------------------------------------------------------- origin access control, function, policies

resource "aws_cloudfront_origin_access_control" "site" {
  name                              = "${var.name}-site"
  description                       = "Signs requests from the ${var.name} distribution to the site bucket"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_cloudfront_function" "spa_rewrite" {
  name    = "${var.name}-spa-rewrite"
  comment = "Sends client-side routes of the single-page app to /index.html (default behaviour only)"
  runtime = "cloudfront-js-2.0"
  publish = true
  code    = file("${path.module}/functions/spa-rewrite.js")
}

resource "aws_cloudfront_origin_request_policy" "api" {
  name    = "${var.name}-api"
  comment = "Cookies, query strings and five named headers for the API origin"

  cookies_config {
    cookie_behavior = "all"
  }

  headers_config {
    header_behavior = "whitelist"

    headers {
      items = local.api_forwarded_headers
    }
  }

  query_strings_config {
    query_string_behavior = "all"
  }
}

resource "aws_cloudfront_response_headers_policy" "this" {
  for_each = local.headers_policies

  name    = "${var.name}-${each.key}"
  comment = "Security headers for the ${each.key} responses"

  security_headers_config {
    dynamic "content_security_policy" {
      for_each = each.value.content_security_policy == null ? [] : [each.value.content_security_policy]

      content {
        content_security_policy = content_security_policy.value
        override                = true
      }
    }

    content_type_options {
      override = true
    }

    frame_options {
      frame_option = "DENY"
      override     = true
    }

    referrer_policy {
      referrer_policy = "same-origin"
      override        = true
    }

    strict_transport_security {
      access_control_max_age_sec = local.hsts_max_age_seconds
      include_subdomains         = true
      preload                    = false
      override                   = true
    }
  }

  custom_headers_config {
    items {
      header   = "Permissions-Policy"
      value    = "camera=(), microphone=(), geolocation=()"
      override = true
    }

    dynamic "items" {
      for_each = each.value.cache_control == null ? [] : [each.value.cache_control]

      content {
        header   = "Cache-Control"
        value    = items.value
        override = true
      }
    }
  }
}

# ---------------------------------------------------------------- the distribution

resource "aws_cloudfront_distribution" "this" {
  enabled             = true
  is_ipv6_enabled     = true
  http_version        = "http2and3"
  price_class         = "PriceClass_100"
  default_root_object = "index.html"
  comment             = var.name

  origin {
    origin_id                = local.site_origin_id
    domain_name              = aws_s3_bucket.site.bucket_regional_domain_name
    origin_access_control_id = aws_cloudfront_origin_access_control.site.id
  }

  origin {
    origin_id   = local.api_origin_id
    domain_name = local.api_origin_domain

    custom_origin_config {
      http_port                = 80
      https_port               = 443
      origin_protocol_policy   = "https-only"
      origin_ssl_protocols     = ["TLSv1.2"]
      origin_read_timeout      = var.api_origin_read_timeout_seconds
      origin_keepalive_timeout = 5
    }

    custom_header {
      name  = "X-Origin-Verify"
      value = var.origin_verify_secret
    }
  }

  ordered_cache_behavior {
    path_pattern               = "/api/*"
    target_origin_id           = local.api_origin_id
    viewer_protocol_policy     = "https-only"
    allowed_methods            = ["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"]
    cached_methods             = ["GET", "HEAD"]
    compress                   = false
    cache_policy_id            = local.caching_disabled_policy_id
    origin_request_policy_id   = aws_cloudfront_origin_request_policy.api.id
    response_headers_policy_id = aws_cloudfront_response_headers_policy.this["api"].id
  }

  ordered_cache_behavior {
    path_pattern               = "/assets/*"
    target_origin_id           = local.site_origin_id
    viewer_protocol_policy     = "redirect-to-https"
    allowed_methods            = ["GET", "HEAD"]
    cached_methods             = ["GET", "HEAD"]
    compress                   = true
    cache_policy_id            = local.caching_optimized_policy_id
    response_headers_policy_id = aws_cloudfront_response_headers_policy.this["assets"].id
  }

  default_cache_behavior {
    target_origin_id           = local.site_origin_id
    viewer_protocol_policy     = "redirect-to-https"
    allowed_methods            = ["GET", "HEAD"]
    cached_methods             = ["GET", "HEAD"]
    compress                   = true
    cache_policy_id            = local.caching_optimized_policy_id
    response_headers_policy_id = aws_cloudfront_response_headers_policy.this["site"].id

    # The only association in the distribution (D19): it must never run on /api/* or /assets/*.
    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.spa_rewrite.arn
    }
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  # The default *.cloudfront.net certificate (no custom domain). Its TLS floor is fixed by CloudFront (L3 in the plan).
  viewer_certificate {
    cloudfront_default_certificate = true
  }

  # No logging_config (Phase 4 L3: access logs would carry query strings) and no custom_error_response (it would replace
  # the API's problem responses). Both are pinned by tests.
}
