<script src="https://js.pusher.com/7.2.0/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@3.0.3/dist/index.min.js"></script>
<script >
    // Gloabl Chat variables from PHP to JS
    window.chatify = {
        name: "{{ config('chat.name') }}",
        sounds: {!! json_encode(config('chat.sounds')) !!},
        allowedImages: {!! json_encode(config('chat.attachments.allowed_images')) !!},
        allowedFiles: {!! json_encode(config('chat.attachments.allowed_files')) !!},
        maxUploadSize: {{ Chatify::getMaxUploadSize() }},
        pusher: {!! json_encode(config('chat.pusher')) !!},
        pusherAuthEndpoint: '{{route("pusher.auth")}}'
    };
    window.chatify.allAllowedExtensions = chatify.allowedImages.concat(chatify.allowedFiles);
</script>
<script src="{{ asset('js/chat/utils.js') }}"></script>
<script src="{{ asset('js/chat/code.js') }}"></script>
