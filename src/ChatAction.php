<?php

declare(strict_types=1);

namespace Eleza\Bot;

/** Activity indicators for Client::sendChatAction(). */
final class ChatAction
{
    public const TYPING = 'typing';
    public const RECORDING_VOICE = 'recording_voice';
    public const RECORDING_VIDEO_NOTE = 'recording_video_note';
    public const UPLOADING_PHOTO = 'uploading_photo';
    public const UPLOADING_VIDEO = 'uploading_video';
    public const UPLOADING_FILE = 'uploading_file';
    public const UPLOADING_VOICE = 'uploading_voice';
    public const UPLOADING_VIDEO_NOTE = 'uploading_video_note';
    public const UPLOADING_GIF = 'uploading_gif';
    public const UPLOADING_AUDIO = 'uploading_audio';
    /** Hides the indicator before it times out. */
    public const CANCEL = 'cancel';
}
