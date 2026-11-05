# Offline tests of modules/edge (D4, D7 site bucket, D17, D18, D19, D25 of the Phase 7 plan): the site bucket and its
# policy, the origin access control, the response-headers policies, the origin request policy, the SPA function and the
# distribution. The AWS provider is mocked, so nothing here can reach an account (scripts/check-tftest-mocks.sh enforces
# the mock in every test file). `command = apply`: under the mock, computed values are only known after apply and ARNs
# are random strings, so the ARNs and the domain the module reads back are pinned below with override_resource.
# Not assertable here: `depends_on` and the ABSENCE of a resource (a second distribution, a bucket ACL); code review covers
# that. The origin-verify secret in this file is a made-up example. The module marks it sensitive, so every assertion that
# touches it is a boolean comparison and no error_message quotes it.
mock_provider "aws" {}

variables {
  name                            = "expense-tracker-dev"
  aws_region                      = "us-east-1"
  bucket_suffix                   = "abcd1234"
  receipts_bucket                 = "expense-tracker-dev-receipts-abcd1234"
  thumbnails_bucket               = "expense-tracker-dev-receipt-thumbnails-abcd1234"
  api_endpoint                    = "https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
  origin_verify_secret            = "example-slot-a-0123456789abcdefghijklmnopqrstuv"
  api_origin_read_timeout_seconds = 30
}

override_resource {
  target = aws_s3_bucket.site
  values = {
    arn = "arn:aws:s3:::expense-tracker-dev-site-abcd1234"
  }
}

override_resource {
  target = module.edge.aws_s3_bucket.site
  values = {
    arn = "arn:aws:s3:::expense-tracker-dev-site-abcd1234"
  }
}

# The provider validates that function_association.function_arn is an ARN.
override_resource {
  target = aws_cloudfront_function.spa_rewrite
  values = {
    arn = "arn:aws:cloudfront::123456789012:function/expense-tracker-dev-spa-rewrite"
  }
}

override_resource {
  target = module.edge.aws_cloudfront_function.spa_rewrite
  values = {
    arn = "arn:aws:cloudfront::123456789012:function/expense-tracker-dev-spa-rewrite"
  }
}

override_resource {
  target = aws_cloudfront_distribution.this
  values = {
    arn         = "arn:aws:cloudfront::123456789012:distribution/EDFDVBD6EXAMPLE"
    domain_name = "d111111abcdef8.cloudfront.net"
  }
}

override_resource {
  target = module.edge.aws_cloudfront_distribution.this
  values = {
    arn         = "arn:aws:cloudfront::123456789012:distribution/EDFDVBD6EXAMPLE"
    domain_name = "d111111abcdef8.cloudfront.net"
  }
}

# ---------------------------------------------------------------- site bucket (D4, D7)

run "the_site_bucket_is_named_after_the_environment_and_the_shared_suffix" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = aws_s3_bucket.site.bucket == "expense-tracker-dev-site-abcd1234"
    error_message = "The site bucket must be <name>-site-<suffix>."
  }

  assert {
    condition     = aws_s3_bucket.site.force_destroy == true
    error_message = "The site bucket must set force_destroy (owner decision 5)."
  }
}

run "the_site_bucket_is_private_acl_free_encrypted_and_unversioned" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = (
      aws_s3_bucket_public_access_block.site.block_public_acls == true
      && aws_s3_bucket_public_access_block.site.block_public_policy == true
      && aws_s3_bucket_public_access_block.site.ignore_public_acls == true
      && aws_s3_bucket_public_access_block.site.restrict_public_buckets == true
    )
    error_message = "All four public access block settings must be on."
  }

  assert {
    condition     = one(aws_s3_bucket_ownership_controls.site.rule).object_ownership == "BucketOwnerEnforced"
    error_message = "Object ownership must be BucketOwnerEnforced."
  }

  assert {
    condition     = one(one(aws_s3_bucket_server_side_encryption_configuration.site.rule).apply_server_side_encryption_by_default).sse_algorithm == "AES256"
    error_message = "The site bucket must use SSE-S3 (AES256)."
  }

  assert {
    condition     = one(aws_s3_bucket_versioning.site.versioning_configuration).status == "Disabled"
    error_message = "Versioning must be off."
  }

  assert {
    condition     = one(one(aws_s3_bucket_lifecycle_configuration.site.rule).abort_incomplete_multipart_upload).days_after_initiation == 1 && one(aws_s3_bucket_lifecycle_configuration.site.rule).status == "Enabled"
    error_message = "Incomplete multipart uploads must be aborted after 1 day."
  }
}

run "the_site_bucket_policy_lets_only_this_distribution_read_objects_and_denies_plain_http" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = length(jsondecode(aws_s3_bucket_policy.site.policy).Statement) == 2
    error_message = "The site bucket policy must have exactly two statements: the CloudFront read and the TLS deny."
  }

  assert {
    condition = (
      [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "AllowCloudFrontServicePrincipalReadOnly"][0].Effect == "Allow"
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "AllowCloudFrontServicePrincipalReadOnly"][0].Principal == { Service = "cloudfront.amazonaws.com" }
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "AllowCloudFrontServicePrincipalReadOnly"][0].Action == "s3:GetObject"
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "AllowCloudFrontServicePrincipalReadOnly"][0].Resource == "${aws_s3_bucket.site.arn}/*"
    )
    error_message = "The only Allow must be s3:GetObject on the bucket's objects for the CloudFront service principal."
  }

  assert {
    condition     = [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "AllowCloudFrontServicePrincipalReadOnly"][0].Condition == { StringEquals = { "AWS:SourceArn" = aws_cloudfront_distribution.this.arn } }
    error_message = "The Allow must be conditioned on AWS:SourceArn equal to this distribution's ARN and nothing else."
  }

  assert {
    condition     = aws_cloudfront_distribution.this.arn == "arn:aws:cloudfront::123456789012:distribution/EDFDVBD6EXAMPLE"
    error_message = "The test pins the distribution ARN; the policy comparison above is meaningless without it."
  }

  assert {
    condition = (
      [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0].Effect == "Deny"
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0].Principal == "*"
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0].Action == "s3:*"
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0].Resource == [aws_s3_bucket.site.arn, "${aws_s3_bucket.site.arn}/*"]
      && [for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0].Condition == { Bool = { "aws:SecureTransport" = "false" } }
    )
    error_message = "The TLS deny must cover the bucket and its objects for every principal."
  }

  # No public Allow and no listing: a missing object is a 403 from S3, and the plan does not ask for ListBucket.
  assert {
    condition     = alltrue([for s in jsondecode(aws_s3_bucket_policy.site.policy).Statement : s.Effect == "Deny" || s.Principal != "*"])
    error_message = "No Allow statement may name every principal."
  }

  assert {
    condition     = !strcontains(aws_s3_bucket_policy.site.policy, "ListBucket")
    error_message = "The policy must not grant s3:ListBucket."
  }
}

# ---------------------------------------------------------------- origin access control

run "the_site_origin_uses_an_origin_access_control_that_always_signs_with_sigv4" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = (
      aws_cloudfront_origin_access_control.site.origin_access_control_origin_type == "s3"
      && aws_cloudfront_origin_access_control.site.signing_behavior == "always"
      && aws_cloudfront_origin_access_control.site.signing_protocol == "sigv4"
    )
    error_message = "The OAC must be an s3 OAC that always signs with sigv4."
  }

  assert {
    condition     = [for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "site"][0].origin_access_control_id == aws_cloudfront_origin_access_control.site.id
    error_message = "The site origin must use the OAC."
  }

  assert {
    condition     = length([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "site"][0].s3_origin_config) == 0 && length([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "site"][0].custom_origin_config) == 0
    error_message = "The site origin must have no origin access identity and no custom origin config (OAC only)."
  }

  assert {
    condition     = length([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "site"][0].custom_header) == 0
    error_message = "The site origin must not carry the origin-verify header."
  }
}

# ---------------------------------------------------------------- the distribution (D17)

run "the_distribution_has_exactly_the_planned_settings" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = (
      aws_cloudfront_distribution.this.enabled == true
      && aws_cloudfront_distribution.this.is_ipv6_enabled == true
      && aws_cloudfront_distribution.this.http_version == "http2and3"
      && aws_cloudfront_distribution.this.price_class == "PriceClass_100"
      && aws_cloudfront_distribution.this.default_root_object == "index.html"
    )
    error_message = "Enabled, IPv6, http2and3, PriceClass_100 and index.html as the default root object (D17)."
  }

  assert {
    condition     = one(aws_cloudfront_distribution.this.viewer_certificate).cloudfront_default_certificate == true
    error_message = "The distribution must use the default CloudFront certificate (no custom domain)."
  }

  # Phase 4 L3: no standard logging, because logs would carry query strings.
  assert {
    condition     = length(aws_cloudfront_distribution.this.logging_config) == 0
    error_message = "The distribution must have no standard logging."
  }

  # Phase 4: a custom error response would mask API problem responses.
  assert {
    condition     = length(aws_cloudfront_distribution.this.custom_error_response) == 0
    error_message = "The distribution must have no custom_error_response."
  }

  assert {
    condition     = length(aws_cloudfront_distribution.this.origin) == 2
    error_message = "The distribution must have exactly two origins: the site bucket and the API."
  }

  assert {
    condition     = length(aws_cloudfront_distribution.this.ordered_cache_behavior) == 2 && [for b in aws_cloudfront_distribution.this.ordered_cache_behavior : b.path_pattern] == ["/api/*", "/assets/*"]
    error_message = "The ordered behaviours must be /api/* then /assets/*."
  }
}

run "the_api_origin_is_https_only_tls_1_2_and_waits_30_seconds" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = [for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].domain_name == "a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
    error_message = "The API origin domain must be api_endpoint without the scheme."
  }

  assert {
    condition = (
      one([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_origin_config).origin_protocol_policy == "https-only"
      && one([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_origin_config).origin_ssl_protocols == toset(["TLSv1.2"])
      && one([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_origin_config).https_port == 443
    )
    error_message = "The API origin must be https-only with TLSv1.2 only on port 443."
  }

  # D12 timeout chain: Lambda 25 < integration 29 < CloudFront 30.
  assert {
    condition     = one([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_origin_config).origin_read_timeout == 30
    error_message = "origin_read_timeout must be 30 seconds."
  }

  assert {
    condition     = [for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].origin_access_control_id == null || [for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].origin_access_control_id == ""
    error_message = "The API origin must not use the S3 origin access control."
  }
}

run "the_api_origin_carries_the_active_slot_in_x_origin_verify_and_nothing_else" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = length([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_header) == 1
    error_message = "The API origin must carry exactly one custom header."
  }

  assert {
    condition     = one([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_header).name == "X-Origin-Verify"
    error_message = "The custom header must be called X-Origin-Verify."
  }

  assert {
    condition     = one([for o in aws_cloudfront_distribution.this.origin : o if o.origin_id == "api"][0].custom_header).value == var.origin_verify_secret
    error_message = "The X-Origin-Verify value must equal the active slot given to the module."
  }

  # The variable is sensitive: a sensitive value in the origin marks the whole origin set, which is how the plan output
  # shows (sensitive value) instead of the secret.
  assert {
    condition     = issensitive(aws_cloudfront_distribution.this.origin)
    error_message = "The origin block holding the secret must be sensitive."
  }
}

run "api_behaviour_never_caches_forwards_everything_needed_and_is_https_only" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = aws_cloudfront_distribution.this.ordered_cache_behavior[0].path_pattern == "/api/*" && aws_cloudfront_distribution.this.ordered_cache_behavior[0].target_origin_id == "api"
    error_message = "/api/* must go to the api origin."
  }

  assert {
    condition     = aws_cloudfront_distribution.this.ordered_cache_behavior[0].cache_policy_id == "4135ea2d-6df8-44a3-9df3-4b5a84be39ad"
    error_message = "/api/* must use the managed CachingDisabled policy."
  }

  assert {
    condition     = aws_cloudfront_distribution.this.ordered_cache_behavior[0].origin_request_policy_id == aws_cloudfront_origin_request_policy.api.id
    error_message = "/api/* must use the module's own origin request policy."
  }

  assert {
    condition     = aws_cloudfront_distribution.this.ordered_cache_behavior[0].viewer_protocol_policy == "https-only"
    error_message = "/api/* must be https-only."
  }

  assert {
    condition     = aws_cloudfront_distribution.this.ordered_cache_behavior[0].allowed_methods == toset(["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"])
    error_message = "/api/* must allow all seven methods."
  }

  assert {
    condition     = aws_cloudfront_distribution.this.ordered_cache_behavior[0].response_headers_policy_id == aws_cloudfront_response_headers_policy.this["api"].id
    error_message = "/api/* must use the API response-headers policy."
  }
}

run "assets_and_default_behaviours_use_caching_optimized_over_https_and_go_to_the_site" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = (
      aws_cloudfront_distribution.this.ordered_cache_behavior[1].path_pattern == "/assets/*"
      && aws_cloudfront_distribution.this.ordered_cache_behavior[1].target_origin_id == "site"
      && aws_cloudfront_distribution.this.ordered_cache_behavior[1].cache_policy_id == "658327ea-f89d-4fab-a63d-7e88639e58f6"
      && aws_cloudfront_distribution.this.ordered_cache_behavior[1].viewer_protocol_policy == "redirect-to-https"
      && aws_cloudfront_distribution.this.ordered_cache_behavior[1].allowed_methods == toset(["GET", "HEAD"])
      && aws_cloudfront_distribution.this.ordered_cache_behavior[1].response_headers_policy_id == aws_cloudfront_response_headers_policy.this["assets"].id
    )
    error_message = "/assets/* must be GET/HEAD to the site, CachingOptimized, redirect-to-https, with the assets headers policy."
  }

  assert {
    condition = (
      one(aws_cloudfront_distribution.this.default_cache_behavior).target_origin_id == "site"
      && one(aws_cloudfront_distribution.this.default_cache_behavior).cache_policy_id == "658327ea-f89d-4fab-a63d-7e88639e58f6"
      && one(aws_cloudfront_distribution.this.default_cache_behavior).viewer_protocol_policy == "redirect-to-https"
      && one(aws_cloudfront_distribution.this.default_cache_behavior).allowed_methods == toset(["GET", "HEAD"])
      && one(aws_cloudfront_distribution.this.default_cache_behavior).response_headers_policy_id == aws_cloudfront_response_headers_policy.this["site"].id
    )
    error_message = "The default behaviour must be GET/HEAD to the site, CachingOptimized, redirect-to-https, with the site headers policy."
  }
}

run "the_spa_function_is_attached_to_the_default_behaviour_only" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = (
      length(one(aws_cloudfront_distribution.this.default_cache_behavior).function_association) == 1
      && one(one(aws_cloudfront_distribution.this.default_cache_behavior).function_association).event_type == "viewer-request"
      && one(one(aws_cloudfront_distribution.this.default_cache_behavior).function_association).function_arn == aws_cloudfront_function.spa_rewrite.arn
    )
    error_message = "The default behaviour must run the SPA function on viewer-request."
  }

  assert {
    condition     = alltrue([for b in aws_cloudfront_distribution.this.ordered_cache_behavior : length(b.function_association) == 0 && length(b.lambda_function_association) == 0])
    error_message = "No other behaviour may run a function (it would rewrite API paths)."
  }

  assert {
    condition     = length(one(aws_cloudfront_distribution.this.default_cache_behavior).lambda_function_association) == 0
    error_message = "The default behaviour must have no Lambda@Edge association."
  }

  assert {
    condition     = aws_cloudfront_function.spa_rewrite.runtime == "cloudfront-js-2.0" && aws_cloudfront_function.spa_rewrite.publish == true
    error_message = "The function must be published on cloudfront-js-2.0."
  }

  assert {
    condition     = strcontains(aws_cloudfront_function.spa_rewrite.code, "function handler(event)") && strcontains(aws_cloudfront_function.spa_rewrite.code, "/index.html")
    error_message = "The function code must be spa-rewrite.js."
  }
}

# ---------------------------------------------------------------- origin request policy (D17)

run "the_origin_request_policy_is_a_whitelist_of_five_headers_without_underscores" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = one(aws_cloudfront_origin_request_policy.api.headers_config).header_behavior == "whitelist"
    error_message = "The headers must be a whitelist, never allViewer."
  }

  assert {
    condition     = one(one(aws_cloudfront_origin_request_policy.api.headers_config).headers).items == toset(["Origin", "X-Requested-With", "Content-Type", "Accept", "CloudFront-Viewer-Address"])
    error_message = "The whitelist must be exactly Origin, X-Requested-With, Content-Type, Accept and CloudFront-Viewer-Address."
  }

  assert {
    condition     = alltrue([for h in one(one(aws_cloudfront_origin_request_policy.api.headers_config).headers).items : !strcontains(h, "_")]) && length(one(one(aws_cloudfront_origin_request_policy.api.headers_config).headers).items) == 5
    error_message = "Five headers, none with an underscore (API Gateway would not see it the way PHP expects)."
  }

  assert {
    condition     = one(aws_cloudfront_origin_request_policy.api.cookies_config).cookie_behavior == "all"
    error_message = "All cookies must be forwarded."
  }

  assert {
    condition     = one(aws_cloudfront_origin_request_policy.api.query_strings_config).query_string_behavior == "all"
    error_message = "All query strings must be forwarded."
  }
}

# ---------------------------------------------------------------- response headers and CSP (D18)

run "every_headers_policy_carries_the_security_block_with_override" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = toset(keys(aws_cloudfront_response_headers_policy.this)) == toset(["site", "assets", "api"])
    error_message = "There must be a headers policy for the site, the assets and the API."
  }

  assert {
    condition = alltrue([
      for p in values(aws_cloudfront_response_headers_policy.this) : (
        one(one(p.security_headers_config).strict_transport_security).access_control_max_age_sec == 63072000
        && one(one(p.security_headers_config).strict_transport_security).include_subdomains == true
        && one(one(p.security_headers_config).strict_transport_security).preload == false
        && one(one(p.security_headers_config).strict_transport_security).override == true
      )
    ])
    error_message = "HSTS must be 2 years, includeSubDomains, no preload, override."
  }

  assert {
    condition     = alltrue([for p in values(aws_cloudfront_response_headers_policy.this) : one(one(p.security_headers_config).content_type_options).override == true])
    error_message = "X-Content-Type-Options: nosniff must override."
  }

  assert {
    condition = alltrue([
      for p in values(aws_cloudfront_response_headers_policy.this) : (
        one(one(p.security_headers_config).frame_options).frame_option == "DENY"
        && one(one(p.security_headers_config).frame_options).override == true
      )
    ])
    error_message = "X-Frame-Options must be DENY with override."
  }

  assert {
    condition = alltrue([
      for p in values(aws_cloudfront_response_headers_policy.this) : (
        one(one(p.security_headers_config).referrer_policy).referrer_policy == "same-origin"
        && one(one(p.security_headers_config).referrer_policy).override == true
      )
    ])
    error_message = "Referrer-Policy must be same-origin with override."
  }

  assert {
    condition = alltrue([
      for p in values(aws_cloudfront_response_headers_policy.this) : (
        [for i in one(p.custom_headers_config).items : i if i.header == "Permissions-Policy"][0].value == "camera=(), microphone=(), geolocation=()"
        && [for i in one(p.custom_headers_config).items : i if i.header == "Permissions-Policy"][0].override == true
      )
    ])
    error_message = "Permissions-Policy must be camera=(), microphone=(), geolocation=() with override."
  }
}

run "the_site_and_assets_policies_carry_the_exact_csp_with_the_s3_hosts_and_the_api_policy_has_none" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = one(one(aws_cloudfront_response_headers_policy.this["site"].security_headers_config).content_security_policy).content_security_policy == join("; ", [
      "default-src 'self'",
      "script-src 'self'",
      "style-src 'self'",
      "img-src 'self' https://expense-tracker-dev-receipt-thumbnails-abcd1234.s3.amazonaws.com",
      "connect-src 'self' https://expense-tracker-dev-receipts-abcd1234.s3.amazonaws.com",
      "object-src 'none'",
      "base-uri 'none'",
      "form-action 'self'",
      "frame-ancestors 'none'",
    ])
    error_message = "The site CSP must be the ADR 0008 target plus the receipts and thumbnails hosts (us-east-1 form)."
  }

  assert {
    condition     = one(one(aws_cloudfront_response_headers_policy.this["assets"].security_headers_config).content_security_policy).content_security_policy == one(one(aws_cloudfront_response_headers_policy.this["site"].security_headers_config).content_security_policy).content_security_policy
    error_message = "The assets policy must carry the same CSP as the site policy."
  }

  assert {
    condition     = alltrue([for k in ["site", "assets"] : one(one(aws_cloudfront_response_headers_policy.this[k].security_headers_config).content_security_policy).override == true])
    error_message = "The CSP must override."
  }

  # CloudFront accepts at most 1,783 characters (Step 0 R10).
  assert {
    condition     = length(one(one(aws_cloudfront_response_headers_policy.this["site"].security_headers_config).content_security_policy).content_security_policy) <= 1783
    error_message = "The CSP must not exceed the 1,783 character CloudFront limit."
  }

  assert {
    condition     = length(one(aws_cloudfront_response_headers_policy.this["api"].security_headers_config).content_security_policy) == 0
    error_message = "The API policy must not set a CSP."
  }
}

run "the_csp_hosts_use_the_regional_s3_form_outside_us_east_1" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  variables {
    aws_region   = "eu-west-1"
    api_endpoint = "https://a1b2c3d4e5.execute-api.eu-west-1.amazonaws.com"
  }

  assert {
    condition     = strcontains(one(one(aws_cloudfront_response_headers_policy.this["site"].security_headers_config).content_security_policy).content_security_policy, "img-src 'self' https://expense-tracker-dev-receipt-thumbnails-abcd1234.s3.eu-west-1.amazonaws.com; connect-src 'self' https://expense-tracker-dev-receipts-abcd1234.s3.eu-west-1.amazonaws.com; ")
    error_message = "Outside us-east-1 the hosts must be <bucket>.s3.<region>.amazonaws.com."
  }
}

run "cache_control_is_no_cache_on_the_site_and_immutable_on_assets_and_untouched_on_the_api" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition = (
      [for i in one(aws_cloudfront_response_headers_policy.this["site"].custom_headers_config).items : i if i.header == "Cache-Control"][0].value == "no-cache"
      && [for i in one(aws_cloudfront_response_headers_policy.this["site"].custom_headers_config).items : i if i.header == "Cache-Control"][0].override == true
    )
    error_message = "The site policy must set Cache-Control: no-cache with override."
  }

  assert {
    condition = (
      [for i in one(aws_cloudfront_response_headers_policy.this["assets"].custom_headers_config).items : i if i.header == "Cache-Control"][0].value == "public, max-age=31536000, immutable"
      && [for i in one(aws_cloudfront_response_headers_policy.this["assets"].custom_headers_config).items : i if i.header == "Cache-Control"][0].override == true
    )
    error_message = "The assets policy must set Cache-Control: public, max-age=31536000, immutable with override."
  }

  assert {
    condition     = length([for i in one(aws_cloudfront_response_headers_policy.this["api"].custom_headers_config).items : i if lower(i.header) == "cache-control"]) == 0
    error_message = "The API policy must not override Cache-Control (PHP sends no-store)."
  }
}

# ---------------------------------------------------------------- outputs

run "outputs_expose_the_domain_the_arn_and_the_bucket_but_not_the_secret" {
  command = apply

  module {
    source = "../../modules/edge"
  }

  assert {
    condition     = output.domain_name == "d111111abcdef8.cloudfront.net" && output.distribution_arn == aws_cloudfront_distribution.this.arn
    error_message = "The module must output the distribution's domain and ARN."
  }

  assert {
    condition     = output.site_bucket == "expense-tracker-dev-site-abcd1234"
    error_message = "The module must output the site bucket name."
  }

  assert {
    condition     = !issensitive(output.domain_name) && !issensitive(output.site_bucket)
    error_message = "Domain and bucket are public values and must not be sensitive."
  }
}

# ---------------------------------------------------------------- variable validation

run "rejects_a_secret_shorter_than_32_characters" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "too-short"
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_a_secret_with_a_space" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "example slot a 0123456789abcdefghijklmnopqrstuv"
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_a_secret_with_a_double_quote" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "example-slot-a-0123456789abcdefghijklmnopqrst\"v"
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_a_secret_with_a_backslash" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "example-slot-a-0123456789abcdefghijklmnopqrst\\v"
  }

  expect_failures = [var.origin_verify_secret]
}

# ORIGIN_VERIFY_SECRETS is comma-separated, so PHP would split a slot that holds a comma and the header would never match.
run "rejects_a_secret_with_a_comma" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "example-slot-a-0123456789abcdefghijklmnop,stuv"
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_a_secret_with_a_non_ascii_character" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "example-slot-a-0123456789abcdefghijklmnopqrstuvé"
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_a_secret_with_a_line_break" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = "example-slot-a-0123456789abcdefghijklmnopqrstuv\n"
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_a_secret_longer_than_cloudfront_allows_in_a_header" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    origin_verify_secret = join("", [for i in range(892) : "ab"])
  }

  expect_failures = [var.origin_verify_secret]
}

run "rejects_an_api_endpoint_over_http" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    api_endpoint = "http://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
  }

  expect_failures = [var.api_endpoint]
}

run "rejects_an_api_endpoint_with_a_path_or_a_trailing_slash" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    api_endpoint = "https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com/"
  }

  expect_failures = [var.api_endpoint]
}

run "rejects_an_api_endpoint_that_is_not_an_execute_api_host" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    api_endpoint = "https://example.com"
  }

  expect_failures = [var.api_endpoint]
}

run "rejects_an_origin_read_timeout_other_than_30" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    api_origin_read_timeout_seconds = 60
  }

  expect_failures = [var.api_origin_read_timeout_seconds]
}

run "rejects_an_origin_read_timeout_not_longer_than_the_integration" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    api_origin_read_timeout_seconds = 29
  }

  expect_failures = [var.api_origin_read_timeout_seconds]
}

run "rejects_a_bucket_name_with_a_dot" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    receipts_bucket = "expense.tracker.receipts"
  }

  expect_failures = [var.receipts_bucket]
}

run "rejects_a_thumbnails_bucket_name_with_an_uppercase_letter" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    thumbnails_bucket = "Expense-Tracker-Thumbs"
  }

  expect_failures = [var.thumbnails_bucket]
}

run "rejects_a_bucket_suffix_that_is_not_8_hex_characters" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    bucket_suffix = "ABCD1234"
  }

  expect_failures = [var.bucket_suffix]
}

run "rejects_a_region_that_is_not_a_region_name" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    aws_region = "us-east-1.evil.example"
  }

  expect_failures = [var.aws_region]
}

run "rejects_a_name_too_long_for_the_site_bucket" {
  command = plan

  module {
    source = "../../modules/edge"
  }

  variables {
    name = "expense-tracker-dev-with-a-very-long-name-that-overflows-s3"
  }

  expect_failures = [var.name]
}

# ---------------------------------------------------------------- wiring without a cycle (D25)

# API -> distribution -> API Lambda environment (APP_ORIGINS) -> integration -> routes, and the receipts CORS rule after
# the distribution. The fixture wires the real edge module with the table, receipts, worker and api modules, as the
# composition (WP10) does. A real cycle would make this apply fail.
run "edge_api_receipts_worker_and_table_wire_together_without_a_cycle" {
  command = apply

  module {
    source = "./tests/fixtures/edge-wiring"
  }

  override_resource {
    target = module.table.aws_dynamodb_table.this
    values = {
      arn = "arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev"
    }
  }

  override_resource {
    target = module.receipts.aws_s3_bucket.receipts
    values = {
      arn = "arn:aws:s3:::expense-tracker-dev-receipts-abcd1234"
    }
  }

  override_resource {
    target = module.receipts.aws_s3_bucket.thumbnails
    values = {
      arn = "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234"
    }
  }

  override_resource {
    target = module.receipts.aws_sqs_queue.receipts
    values = {
      arn = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"
    }
  }

  override_resource {
    target = module.receipts.aws_sqs_queue.dlq
    values = {
      arn = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts-dlq"
    }
  }

  override_resource {
    target = module.api.aws_apigatewayv2_api.this
    values = {
      api_endpoint  = "https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
      execution_arn = "arn:aws:execute-api:us-east-1:123456789012:a1b2c3d4e5"
    }
  }

  override_resource {
    target = module.api.aws_cloudwatch_log_group.access
    values = {
      arn = "arn:aws:logs:us-east-1:123456789012:log-group:/aws/apigateway/expense-tracker-dev"
    }
  }

  override_resource {
    target = module.api.module.function.aws_lambda_function.this
    values = {
      code_sha256 = "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
    }
  }

  override_resource {
    target = module.worker.module.function.aws_lambda_function.this
    values = {
      code_sha256 = "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
    }
  }

  override_resource {
    target = module.api.module.function.aws_iam_role.this
    values = {
      arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-api"
    }
  }

  override_resource {
    target = module.worker.module.function.aws_iam_role.this
    values = {
      arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
    }
  }

  override_resource {
    target = module.edge.aws_s3_bucket.site
    values = {
      arn = "arn:aws:s3:::expense-tracker-dev-site-abcd1234"
    }
  }

  override_resource {
    target = module.edge.aws_cloudfront_distribution.this
    values = {
      arn         = "arn:aws:cloudfront::123456789012:distribution/EDFDVBD6EXAMPLE"
      domain_name = "d111111abcdef8.cloudfront.net"
    }
  }

  variables {
    package_path      = "../../../../build/lambda/backend.zip"
    package_sha256    = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    php_layer_version = 20
    gd_layer_version  = 1
    aws_account_id    = "123456789012"

    # Two slots; the distribution is given the first (the active one).
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,example-slot-b-9876543210zyxwvutsrqponmlkjihgfe"
  }

  assert {
    condition     = output.api_app_origins == "https://d111111abcdef8.cloudfront.net" && output.api_app_origins == output.cors_origin
    error_message = "APP_ORIGINS and the receipts CORS origin must both be the real edge module's domain over https."
  }

  assert {
    condition     = output.edge_domain_name == "d111111abcdef8.cloudfront.net"
    error_message = "The fixture must read the domain from the edge module."
  }

  assert {
    condition     = output.edge_api_origin_domain == "a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
    error_message = "The distribution's API origin must be the API module's endpoint."
  }

  assert {
    condition     = output.edge_csp_mentions_both_buckets == true
    error_message = "The CSP must name the receipts module's two buckets."
  }
}
