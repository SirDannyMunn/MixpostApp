curl -X POST "https://api.replicate.com/v1/predictions" \
  -H "Authorization: Bearer 536521752851d7604e933100b7b60de4bbda04f0" \
  -H "Content-Type: application/json" \
  -d @- <<'EOF'
{
  "version": "wan-video/wan-2.6-i2v",
  "input": {
    "image": "https://770957583915-web-public-general.s3.amazonaws.com/images/monk-temple.png",
    "audio": "blob:https://replicate.com/93f62ae8-e30a-46ac-9e0d-50769dd661f4",
    "prompt": "{\"style\":\"Hyper-realistic cinematic documentary, natural color science, zero stylization, indistinguishable from live-action footage\",\"shot\":{\"duration\":8,\"composition\":\"static medium-wide shot at seated eye level (≈35 mm equiv.), monk centered, symmetrical temple altar fully visible behind\",\"camera_motion\":\"none (locked tripod)\",\"frame_rate\":\"24 fps\",\"resolution\":\"4K UHD\",\"image_character\":\"true-to-life skin texture, natural micro-contrast, no AI artifacts\"},\"timeline\":[{\"time\":\"0–2 s\",\"action\":\"Monk sits cross-legged on a raised cushion, hands resting lightly in lap. Eyes steady, calm breathing visible in chest and shoulders.\"},{\"time\":\"2–6 s\",\"action\":\"Monk begins speaking softly about spirituality; lips move naturally, slight jaw and throat motion. Subtle head nods emphasize key words; gaze remains grounded and present.\"},{\"time\":\"6–8 s\",\"action\":\"Brief pause between phrases; monk inhales slowly, eyes soften, expression remains composed and contemplative.\"}],\"subject\":{\"monk\":{\"appearance\":\"adult Buddhist monk with shaved head, wearing traditional saffron robe draped over one shoulder\",\"expression\":\"serene, focused, emotionally grounded\",\"movement\":\"minimal, intentional, consistent with meditation practice\"}},\"scene\":{\"location\":\"ornate Buddhist temple interior with gold statues, carved wood panels, ritual objects and candles\",\"atmosphere\":\"quiet, reverent, incense-hazed air barely visible in light\"},\"lighting\":{\"primary\":\"warm ambient temple lighting from candles and lanterns (≈3200 K)\",\"secondary\":\"soft natural daylight spill from side opening, gentle highlights on face and robe folds\"},\"audio\":{\"dialogue\":\"clear, close-mic'd voice, calm pacing\",\"ambient\":\"faint candle flicker, distant temple stillness\"},\"visual_rules\":{\"prohibited_elements\":[\"cinematic filters or LUT exaggeration\",\"AI-style depth blur or face smoothing\",\"dramatic camera moves\",\"modern objects or signage\",\"overly theatrical acting\"]}}",
    "duration": 5,
    "resolution": "720p"
  }
}
EOF
