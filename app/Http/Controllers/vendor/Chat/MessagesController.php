<?php


use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use App\Models\User;
use App\Models\ChMessage as Message;
use App\Models\ChFavorite as Favorite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as FacadesRequest;
use Illuminate\Support\Str;
class MessagesController extends Controller
{
    protected $perPage = 30;

    /**
     * Authenticate the connection for pusher
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function pusherAuth(Request $request)
    {
        return Chat::pusherAuth(
            $request->user(),
            Auth::user(),
            $request['channel_name'],
            $request['socket_id']
        );
    }

    /**
     * Returning the view of the app with the required data.
     *
     * @param int $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function index( $id = null)
    {
        $messenger_color = Auth::user()->messenger_color;
        return view('Chat::pages.app', [
            'id' => $id ?? 0,
            'messengerColor' => $messenger_color ? $messenger_color : Chat::getFallbackColor(),
            'dark_mode' => Auth::user()->dark_mode < 1 ? 'light' : 'dark',
        ]);
    }


    /**
     * Fetch data (user, favorite.. etc).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function idFetchData(Request $request)
    {
        $favorite = Chat::inFavorite($request['id']);
        $fetch = User::where('id', $request['id'])->first();
        if($fetch){
            $userAvatar = Chat::getUserWithAvatar($fetch)->avatar;
        }
        return Response::json([
            'favorite' => $favorite,
            'fetch' => $fetch ?? null,
            'user_avatar' => $userAvatar ?? null,
        ]);
    }

    /**
     * This method to make a links for the attachments
     * to be downloadable.
     *
     * @param string $fileName
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|void
     */
    public function download($fileName)
    {
        $filePath = config('chat.attachments.folder') . '/' . $fileName;
        if (Chat::storage()->exists($filePath)) {
            return Chat::storage()->download($filePath);
        }
        return abort(404, "Sorry, File does not exist in our server or may have been deleted!");
    }

    /**
     * Send a message to database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function send(Request $request)
    {
        // default variables
        $error = (object)[
            'status' => 0,
            'message' => null
        ];
        $attachment = null;
        $attachment_title = null;

        // if there is attachment [file]
        if ($request->hasFile('file')) {
            // allowed extensions
            $allowed_images = Chat::getAllowedImages();
            $allowed_files  = Chat::getAllowedFiles();
            $allowed        = array_merge($allowed_images, $allowed_files);

            $file = $request->file('file');
            // check file size
            if ($file->getSize() < Chat::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed)) {
                    // get attachment name
                    $attachment_title = $file->getClientOriginalName();
                    // upload attachment and store the new name
                    $attachment = Str::uuid() . "." . $file->extension();
                    $file->storeAs(config('chat.attachments.folder'), $attachment, config('chat.storage_disk_name'));
                } else {
                    $error->status = 1;
                    $error->message = "File extension not allowed!";
                }
            } else {
                $error->status = 1;
                $error->message = "File size you are trying to upload is too large!";
            }
        }

        if (!$error->status) {
            $message = Chat::newMessage([
                'from_id' => Auth::user()->id,
                'to_id' => $request['id'],
                'body' => htmlentities(trim($request['message']), ENT_QUOTES, 'UTF-8'),
                'attachment' => ($attachment) ? json_encode((object)[
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim($attachment_title), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);
            $messageData = Chat::parseMessage($message);
            if (Auth::user()->id != $request['id']) {
                Chat::push("private-chat.".$request['id'], 'messaging', [
                    'from_id' => Auth::user()->id,
                    'to_id' => $request['id'],
                    'message' => Chat::messageCard($messageData, true)
                ]);
            }
        }

        // send the response
        return Response::json([
            'status' => '200',
            'error' => $error,
            'message' => Chat::messageCard(@$messageData),
            'tempID' => $request['temporaryMsgId'],
        ]);
    }

    /**
     * fetch [user/group] messages from database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function fetch(Request $request)
    {
        $query = Chat::fetchMessagesQuery($request['id'])->latest();
        $messages = $query->paginate($request->per_page ?? $this->perPage);
        $totalMessages = $messages->total();
        $lastPage = $messages->lastPage();
        $response = [
            'total' => $totalMessages,
            'last_page' => $lastPage,
            'last_message_id' => collect($messages->items())->last()->id ?? null,
            'messages' => '',
        ];

        // if there is no messages yet.
        if ($totalMessages < 1) {
            $response['messages'] ='<p class="message-hint center-el"><span>Say \'hi\' and start messaging</span></p>';
            return Response::json($response);
        }
        if (count($messages->items()) < 1) {
            $response['messages'] = '';
            return Response::json($response);
        }
        $allMessages = null;
        foreach ($messages->reverse() as $message) {
            $allMessages .= Chat::messageCard(
                Chat::parseMessage($message)
            );
        }
        $response['messages'] = $allMessages;
        return Response::json($response);
    }

    /**
     * Make messages as seen
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function seen(Request $request)
    {
        // make as seen
        $seen = Chat::makeSeen($request['id']);
        // send the response
        return Response::json([
            'status' => $seen,
        ], 200);
    }

    /**
     * Get contacts list
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getContacts(Request $request)
    {
        // get all users that received/sent message from/to [Auth user]
        $users = Message::join('users',  function ($join) {
            $join->on('ch_messages.from_id', '=', 'users.id')
                ->orOn('ch_messages.to_id', '=', 'users.id');
        })
        ->where(function ($q) {
            $q->where('ch_messages.from_id', Auth::user()->id)
            ->orWhere('ch_messages.to_id', Auth::user()->id);
        })
        ->where('users.id','!=',Auth::user()->id)
        ->select('users.*',DB::raw('MAX(ch_messages.created_at) max_created_at'))
        ->orderBy('max_created_at', 'desc')
        ->groupBy('users.id')
        ->paginate($request->per_page ?? $this->perPage);

        $usersList = $users->items();

        if (count($usersList) > 0) {
            $contacts = '';
            foreach ($usersList as $user) {
                $contacts .= Chat::getContactItem($user);
            }
        } else {
            $contacts = '<p class="message-hint center-el"><span>Your contact list is empty</span></p>';
        }

        return Response::json([
            'contacts' => $contacts,
            'total' => $users->total() ?? 0,
            'last_page' => $users->lastPage() ?? 1,
        ], 200);
    }

    /**
     * Update user's list item data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateContactItem(Request $request)
    {
        // Get user data
        $user = User::where('id', $request['user_id'])->first();
        if(!$user){
            return Response::json([
                'message' => 'User not found!',
            ], 401);
        }
        $contactItem = Chat::getContactItem($user);

        // send the response
        return Response::json([
            'contactItem' => $contactItem,
        ], 200);
    }

    /**
     * Put a user in the favorites list
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function favorite(Request $request)
    {
        $userId = $request['user_id'];
        // check action [star/unstar]
        $favoriteStatus = Chat::inFavorite($userId) ? 0 : 1;
        Chat::makeInFavorite($userId, $favoriteStatus);

        // send the response
        return Response::json([
            'status' => @$favoriteStatus,
        ], 200);
    }

    /**
     * Get favorites list
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function getFavorites(Request $request)
    {
        $favoritesList = null;
        $favorites = Favorite::where('user_id', Auth::user()->id);
        foreach ($favorites->get() as $favorite) {
            // get user data
            $user = User::where('id', $favorite->favorite_id)->first();
            $favoritesList .= view('Chat::layouts.favorite', [
                'user' => $user,
            ]);
        }
        // send the response
        return Response::json([
            'count' => $favorites->count(),
            'favorites' => $favorites->count() > 0
                ? $favoritesList
                : 0,
        ], 200);
    }

    /**
     * Search in messenger
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function search(Request $request)
    {
        $getRecords = null;
        $input = trim(filter_var($request['input']));
        $records = User::where('id','!=',Auth::user()->id)
                    ->where('name', 'LIKE', "%{$input}%")
                    ->paginate($request->per_page ?? $this->perPage);
        foreach ($records->items() as $record) {
            $getRecords .= view('Chat::layouts.listItem', [
                'get' => 'search_item',
                'user' => Chat::getUserWithAvatar($record),
            ])->render();
        }
        if($records->total() < 1){
            $getRecords = '<p class="message-hint center-el"><span>Nothing to show.</span></p>';
        }
        // send the response
        return Response::json([
            'records' => $getRecords,
            'total' => $records->total(),
            'last_page' => $records->lastPage()
        ], 200);
    }

    /**
     * Get shared photos
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function sharedPhotos(Request $request)
    {
        $shared = Chat::getSharedPhotos($request['user_id']);
        $sharedPhotos = null;

        // shared with its template
        for ($i = 0; $i < count($shared); $i++) {
            $sharedPhotos .= view('Chat::layouts.listItem', [
                'get' => 'sharedPhoto',
                'image' => Chat::getAttachmentUrl($shared[$i]),
            ])->render();
        }
        // send the response
        return Response::json([
            'shared' => count($shared) > 0 ? $sharedPhotos : '<p class="message-hint"><span>Nothing shared yet</span></p>',
        ], 200);
    }

    /**
     * Delete conversation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteConversation(Request $request)
    {
        // delete
        $delete = Chat::deleteConversation($request['id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    /**
     * Delete message
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteMessage(Request $request)
    {
        // delete
        $delete = Chat::deleteMessage($request['id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    public function updateSettings(Request $request)
    {
        $msg = null;
        $error = $success = 0;

        // dark mode
        if ($request['dark_mode']) {
            $request['dark_mode'] == "dark"
                ? User::where('id', Auth::user()->id)->update(['dark_mode' => 1])  // Make Dark
                : User::where('id', Auth::user()->id)->update(['dark_mode' => 0]); // Make Light
        }

        // If messenger color selected
        if ($request['messengerColor']) {
            $messenger_color = trim(filter_var($request['messengerColor']));
            User::where('id', Auth::user()->id)
                ->update(['messenger_color' => $messenger_color]);
        }
        // if there is a [file]
        if ($request->hasFile('avatar')) {
            // allowed extensions
            $allowed_images = Chat::getAllowedImages();

            $file = $request->file('avatar');
            // check file size
            if ($file->getSize() < Chat::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed_images)) {
                    // delete the older one
                    if (Auth::user()->avatar != config('chat.user_avatar.default')) {
                        $avatar = Auth::user()->avatar;
                        if (Chat::storage()->exists($avatar)) {
                            Chat::storage()->delete($avatar);
                        }
                    }
                    // upload
                    $avatar = Str::uuid() . "." . $file->extension();
                    $update = User::where('id', Auth::user()->id)->update(['avatar' => $avatar]);
                    $file->storeAs(config('chat.user_avatar.folder'), $avatar, config('chat.storage_disk_name'));
                    $success = $update ? 1 : 0;
                } else {
                    $msg = "File extension not allowed!";
                    $error = 1;
                }
            } else {
                $msg = "File size you are trying to upload is too large!";
                $error = 1;
            }
        }

        // send the response
        return Response::json([
            'status' => $success ? 1 : 0,
            'error' => $error ? 1 : 0,
            'message' => $error ? $msg : 0,
        ], 200);
    }

    /**
     * Set user's active status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function setActiveStatus(Request $request)
    {
        $activeStatus = $request['status'] > 0 ? 1 : 0;
        $status = User::where('id', Auth::user()->id)->update(['active_status' => $activeStatus]);
        return Response::json([
            'status' => $status,
        ], 200);
    }
}
