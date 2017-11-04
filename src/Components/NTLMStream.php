<?php
/*
* Copyright (c) 2008 Invest-In-France Agency http://www.invest-in-france.org
*
* Author : Thomas Rabaix
*
* Permission to use, copy, modify, and distribute this software for any
* purpose with or without fee is hereby granted, provided that the above
* copyright notice and this permission notice appear in all copies.
*
* THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
* WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
* MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
* ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
* WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
* ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
* OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/

namespace DreamFactory\Core\Soap\Components;

class NTLMStream
{
    private $path;
    private $mode;
    private $options;
    private $opened_path;
    private $buffer;
    private $pos;

    public $ch;

    public static $user;
    public static $password;

    /**
     * Open the stream
     *
     * @param mixed $path
     * @param mixed $mode
     * @param mixed $options
     * @param mixed $opened_path
     *
     * @return bool
     */
    public function stream_open($path, $mode, $options, $opened_path)
    {
        \Log::debug("[NTLMStream::stream_open] $path , mode=$mode ");
        $this->path = $path;
        $this->mode = $mode;
        $this->options = $options;
        $this->opened_path = $opened_path;

        $this->createBuffer($path);

        return true;
    }

    /**
     * Close the stream
     *
     */
    public function stream_close()
    {
        \Log::debug("[NTLMStream::stream_close] ");
        curl_close($this->ch);
    }

    /**
     * Read the stream
     *
     * @param int $count number of bytes to read
     *
     * @return mixed content from pos to count
     */
    public function stream_read($count)
    {
        \Log::debug("[NTLMStream::stream_read] $count ");
        if (strlen($this->buffer) == 0) {
            return false;
        }

        $read = substr($this->buffer, $this->pos, $count);

        $this->pos += $count;

        return $read;
    }

    /**
     * write the stream
     *
     * @param int $data number of bytes to read
     *
     * @return mixed content from pos to count
     */
    public function stream_write($data)
    {
        \Log::debug("[NTLMStream::stream_write] ");
        if (strlen($this->buffer) == 0) {
            return false;
        }

        return true;
    }

    /**
     *
     * @return true if eof else false
     */
    public function stream_eof()
    {
        \Log::debug("[NTLMStream::stream_eof] ");

        if ($this->pos > strlen($this->buffer)) {
            //echo "true \n";
            return true;
        }

        \Log::debug("false");

        return false;
    }

    /**
     * @return int the position of the current read pointer
     */
    public function stream_tell()
    {
        \Log::debug("[NTLMStream::stream_tell] ");

        return $this->pos;
    }

    /**
     * Flush stream data
     */
    public function stream_flush()
    {
        \Log::debug("[NTLMStream::stream_flush] ");
        $this->buffer = null;
        $this->pos = null;
    }

    /**
     * Stat the file, return only the size of the buffer
     *
     * @return array stat information
     */
    public function stream_stat()
    {
        \Log::debug("[NTLMStream::stream_stat] ");

        $this->createBuffer($this->path);
        $stat = array(
            'size' => strlen($this->buffer),
        );

        return $stat;
    }

    /**
     * Stat the url, return only the size of the buffer
     *
     * @return array stat information
     */
    public function url_stat($path, $flags)
    {
        \Log::debug("[NTLMStream::url_stat] ");
        $this->createBuffer($path);
        $stat = array(
            'size' => strlen($this->buffer),
        );

        return $stat;
    }

    /**
     * Create the buffer by requesting the url through cURL
     *
     * @param mixed $path
     */
    private function createBuffer($path)
    {
        if ($this->buffer) {
            return;
        }

        \Log::debug("[NTLMStream::createBuffer] create buffer from : $path");
        $this->ch = curl_init($path);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($this->ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($this->ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($this->ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($this->ch, CURLOPT_USERPWD, static::$user . ':' . static::$password);
        $this->buffer = curl_exec($this->ch);

        \Log::debug("[NTLMStream::createBuffer] buffer size : " . strlen($this->buffer) . "bytes");
        $this->pos = 0;
    }
}