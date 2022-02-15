# rt2zammad
RT Export + Zammad Import

The code was originally developed by user fgaspar (Federico Gasparrini) and was found via the following thread
https://community.zammad.org/t/re-migration-from-rt/8660 - This was the url that was provided me by a teammate, but I got a 404 following it.

fgaspar's code had very few comments, so had to do some reverse engineering to figure some of the stuff out...

Other items:
* Had to add sql command to create the rt_zammad table that is used by the code...  Assumed the code is just two integers as they are ticket ids.
