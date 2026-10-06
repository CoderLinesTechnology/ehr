<?php

use Illuminate\Support\Facades\Route;

// Staff application: reports module. Loaded inside the /o/{organization} group (middleware: auth, verified, tenant;
// name prefix "app."). Owned by the reports module. Every route needs its permission (reports.view | reports.export) and, where the
// plan gates the module, its entitlement. The sidebar item appears once `app.reports.index` exists.
