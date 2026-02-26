#!/bin/bash
curl 'https://velocity.dev/api/v1/lead-watcher/agents/019bf56e-55a8-7205-b99a-7aa24bfed433' \
  -X 'PUT' \
  -H 'accept: application/json' \
  -H 'authorization: Bearer 29|bhvzR5G9hEgQLJoXAtJAEb40NiHnkPtJoxllE1SW163423f7' \
  -H 'content-type: application/json' \
  -H 'x-organization-id: 019bc26a-609b-70fc-b41f-d7d484a2dc49' \
  --data-raw '{"name":"Test ICP - SaaS Decision Makers","icp_profile_id":"019bf56e-55a8-7205-b99a-7aa24bfed433","precision_mode":"high_precision","signals_config":{"influencer_profiles":["linkedin.com/in/romàn-czerny-11b773199"],"engagement_keywords":[{"keyword":"sales automation","track":"all"},{"keyword":"b2b sales","track":"all"},{"keyword":"outreach","track":"all"}],"trigger_events":{"top_5_percent":false,"recently_raised_funds":false,"recent_job_changes":false}}}'
