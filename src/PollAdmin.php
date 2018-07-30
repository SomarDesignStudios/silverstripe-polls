<?php

namespace Mateusz\Polls;

use SilverStripe\Admin\ModelAdmin;

class PollAdmin extends ModelAdmin {

    private static $url_segment = 'polls';

    private static $menu_title = 'Polls';

    private static $managed_models = [
        Poll::class
    ];

}