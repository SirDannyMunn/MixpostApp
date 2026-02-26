curl --location 'https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/video-generation/video-synthesis' \
    -H 'X-DashScope-Async: enable' \
    -H "Authorization: Bearer sk-3fba9d33227246ffb5d0d71fbd624f6b" \
    -H 'Content-Type: application/json' \
    -d '{
     "model": "wan2.6-i2v",
    "input": {
        "prompt": "A scene of urban fantasy art. A dynamic graffiti art character. A boy painted with spray paint comes to life from a concrete wall. He sings an English rap song at a very fast pace while striking a classic, energetic rapper pose. The scene is set under an urban railway bridge at night. The lighting comes from a single streetlight, creating a cinematic atmosphere full of high energy and amazing detail. The audio of the video consists entirely of his rap, with no other dialogue or noise.",
        "img_url": "https://help-static-aliyun-doc.aliyuncs.com/file-manage-files/zh-CN/20250925/wpimhv/rap.png"
    },
    "parameters": {
        "resolution": "720P",
        "prompt_extend": true,
        "duration": 10,
        "audio": true,
        "shot_type":"multi"
    }
}'