<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace qtype_drawing;

/**
 * Stream wrapper exposing an in-memory blob as a readable file stream.
 *
 * Used by the renderer to run getimagesize() on image data held in memory
 * without having to write a temporary file first.
 *
 * @package    qtype_drawing
 * @copyright  ETHZ LET <amr.hourani@id.ethz.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blob_data_as_file_stream {
    /** @var int Current position within the blob data. */
    private static $blobdataposition = 0;

    /** @var string The blob data served by this stream wrapper. */
    public static $blobdatastream = '';

    /** @var resource|null Stream context, set by the PHP streams API. */
    public $context;

    /**
     * Open the stream and reset the position.
     *
     * @param string $path The path or URL that was opened.
     * @param string $mode The mode used to open the stream.
     * @param int $options Additional flags set by the streams API.
     * @param string|null $openedpath Set to the path that was actually opened.
     * @return bool True on success.
     */
    public static function stream_open($path, $mode, $options, &$openedpath) {
        static::$blobdataposition = 0;
        return true;
    }

    /**
     * Seek to a specific position in the stream.
     *
     * @param int $seekoffset The offset to seek to.
     * @param int $seekwhence One of SEEK_SET, SEEK_CUR or SEEK_END.
     * @return bool True if the position was updated.
     */
    public static function stream_seek($seekoffset, $seekwhence) {
        $blobdatalength = strlen(static::$blobdatastream);
        switch ($seekwhence) {
            case SEEK_SET:
                $newblobdataposition = $seekoffset;
                break;
            case SEEK_CUR:
                $newblobdataposition = static::$blobdataposition + $seekoffset;
                break;
            case SEEK_END:
                $newblobdataposition = $blobdatalength + $seekoffset;
                break;
            default:
                return false;
        }
        if (($newblobdataposition >= 0) && ($newblobdataposition <= $blobdatalength)) {
            static::$blobdataposition = $newblobdataposition;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Return the current position in the stream.
     *
     * @return int The current position.
     */
    public static function stream_tell() {
        return static::$blobdataposition;
    }

    /**
     * Read data from the stream.
     *
     * @param int $readbuffersize How many bytes to read.
     * @return string The data read from the stream.
     */
    public static function stream_read($readbuffersize) {
        $readdata = substr(static::$blobdatastream, static::$blobdataposition, $readbuffersize);
        static::$blobdataposition += strlen($readdata);
        return $readdata;
    }

    /**
     * Write data to the stream at the current position.
     *
     * @param string $writedata The data to write.
     * @return int The number of bytes written.
     */
    public static function stream_write($writedata) {
        $writedatalength = strlen($writedata);
        static::$blobdatastream = substr(static::$blobdatastream, 0, static::$blobdataposition) .
        $writedata . substr(static::$blobdatastream, static::$blobdataposition += $writedatalength);
        return $writedatalength;
    }

    /**
     * Check whether the end of the stream has been reached.
     *
     * @return bool True if the position is at or past the end of the data.
     */
    public static function stream_eof() {
        return static::$blobdataposition >= strlen(static::$blobdatastream);
    }
}
