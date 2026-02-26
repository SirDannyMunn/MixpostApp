# Set API token from environment (do not commit secrets)
API_TOKEN="${APIFY_API_TOKEN:-}"
if [ -z "$API_TOKEN" ]; then
  echo "APIFY_API_TOKEN is required"
  exit 1
fi

# Prepare Actor input
cat > input.json <<'EOF'
{
    "excludePinnedPosts": false,
    "resultsPerPage": 50,
    "searchQueries": [
        "seo"
    ],
    "shouldDownloadCovers": false,
    "shouldDownloadSlideshowImages": false,
    "shouldDownloadSubtitles": true,
    "shouldDownloadVideos": true
}
EOF

# Run the Actor
curl "https://api.apify.com/v2/acts/OtzYfK1ndEGdwWFKQ/runs?token=$API_TOKEN" \
  -X POST \
  -d @input.json \
  -H 'Content-Type: application/json'
