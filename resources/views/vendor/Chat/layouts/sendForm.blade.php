<div class="messenger-sendCard">
    <form id="message-form" method="POST" action="{{ route('send.message') }}" enctype="multipart/form-data">
        @csrf
        <label><span class="fas fa-list-alt"></span><input disabled='disabled' type="file" class="upload-attachment"
        name="file"
        accept=".{{implode(', .',config('chat.attachments.allowed_images'))}},
        .{{implode(', .',config('chat.attachments.allowed_files'))}}"/></label>
        <button class="emoji-button"></span><span class="fas fa-smile"></button>
        <textarea readonly='readonly' name="message" class="m-send app-scroll" placeholder="Type text.."></textarea>
        <button disabled='disabled' class="send-button"><span class="fas fa-comment"></span></button>
    </form>
</div>
