<?php

namespace Swooliy\Watcher;

use Closure;
use Swooliy\Watcher\Exceptions\PidNotFoundException;

/**
 * Watcher
 *
 * @category Watcher
 * @package  Swooliy\Watcher
 * @author   ney <zoobile@gmail.com>
 * @license  MIT https://github.com/swooliy/watcher/LICENSE.md
 * @link     https://github.com/swooliy/watcher
 */
class Watcher
{
    /**
     * Default events Type need to watch
     */
    const MASK = IN_MODIFY | IN_DELETE | IN_CREATE | IN_MOVE;

    /**
     * Default file type need to watch
     */
    const FILE_TYPE = ['php'];

    /**
     * Pid of the master process
     *
     * @var int
     */
    protected $pid;

    /**
     * Files which need watched
     *
     * @var array
     */
    public $files;

    /**
     * Root directories
     *
     * @var array
     */
    protected $rootDirs = [];

    /**
     * Watching files map
     *
     * Key is file's path, value is the inotify_add_watch result
     */
    protected $watching = [];

    /**
     * Inotify
     *
     * @var
     */
    protected $inotify = false;

    /**
     * Reloading status
     *
     * @var boolean
     */
    public $reloading = false;

    /**
     * Callback when watching files has changed
     *
     * @var function
     */
    protected $callback;

    /**
     * Constructor
     *
     * @param array $files    the files which need watched
     * @param func  $callback the callback function when the watching files has changed
     * 
     * @return void
     */
    public function __construct(array $files, Closure $callback)
    {
        $this->callback = $callback;
        $this->files    = $files;
        $this->inotify  = inotify_init();
    }

    /**
     * Watching mode started
     *
     * @return void
     */
    public function run()
    {
        echo "watching...\n";
        if (posix_kill($this->pid, 0) === false) {
            throw new PidNotFoundException("Process#{$this->pid} not found.");
        }

        foreach ($this->files as $file) {
            $this->watch($file, true);
        }

        swoole_event_add(
            $this->inotify,
            function ($ifd) {
                $events = inotify_read($this->inotify);

                if (!$events) {
                    return;
                }

                foreach ($events as $ev) {
                    if ($ev['mask'] == IN_IGNORED) {
                        continue;
                    } else if ($ev['mask'] == IN_CREATE or $ev['mask'] == IN_DELETE or $ev['mask'] == IN_MODIFY or $ev['mask'] == IN_MOVED_TO or $ev['mask'] == IN_MOVED_FROM) {
                        if (!$this->reloading) {
                            echo "after 1 seconds reload the server\n";
                            // swoole_timer_after(1 * 1000, [$this, 'reload']);
                            swoole_timer_after(1 * 1000, [$this, 'trigger']);
                            $this->reloading = true;
                        }
                    }
                }
            }
        );

        swoole_event_wait();
    }

    /**
     * Watch
     *
     * @param string $dir  the watch directory
     * @param bool   $root whether the directory is root
     *
     * @return void
     */
    public function watch($dir, $root = true)
    {
        if (!is_dir($dir)) {
            throw new WatchfilesNotFoundException("[$dir] is not a directory.");
        }

        if (isset($this->watching[$dir])) {
            return;
        }

        if ($root) {
            $this->rootDirs[] = $dir;
        }
        $wd                   = inotify_add_watch($this->inotify, $dir, self::MASK);
        $this->watching[$dir] = $wd;

        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f == '.' or $f == '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            if (is_dir($path)) {
                $this->watch($path, false);
            }
            $fileType = strrchr($f, '.');
            if (isset(self::FILE_TYPE[$fileType])) {
                $wd                    = inotify_add_watch($this->inotify, $path, self::MASK);
                $this->watching[$path] = $wd;
            }
        }
    }

    /**
     * Trigger the callback function when watching files has changed
     *
     * @return void
     */
    protected function trigger()
    {
        call_user_func($this->callback, $this);
    }

    /**
     * Remove watching files
     *
     * @return void
     */
    public function clear()
    {
        foreach ($this->watching as $descriptor) {
            inotify_rm_watch($this->inotify, $descriptor);
        }

        $this->watching = [];
    }

}
