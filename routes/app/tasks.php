<?php

use Illuminate\Support\Facades\Route;

// Staff application: tasks module. Loaded inside the /o/{organization} group (middleware: auth, verified, tenant;
// name prefix "app."). Owned by the tasks module. Every route needs its permission (tasks.view | tasks.view_all | tasks.manage) and, where the
// plan gates the module, its entitlement. The sidebar item appears once `app.tasks.index` exists.
