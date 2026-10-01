<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\V2\Message;

class MessageController extends Controller
{
    public function index()
    {
        return view('v2.admin.messages.index', ['items' => Message::latest()->paginate(25)]);
    }

    public function show(Message $message)
    {
        if (! $message->read_at) {
            $message->update(['read_at' => now()]);
        }

        return view('v2.admin.messages.show', ['m' => $message]);
    }

    public function destroy(Message $message)
    {
        $message->delete();

        return redirect()->route('v2.admin.messages')->with('ok', 'Message deleted.');
    }
}
