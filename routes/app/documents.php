<?php

use Illuminate\Support\Facades\Route;

// Staff application: documents module. Loaded inside the /o/{organization} group (middleware: auth, verified, tenant;
// name prefix "app."). Owned by the documents module. Every route needs its permission (documents.view | documents.view_clinical | documents.upload | documents.manage) and, where the
// plan gates the module, its entitlement. The sidebar item appears once `app.documents.index` exists.
