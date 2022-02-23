# rt2zammad
RT Export + Zammad Import

## Description
The code was originally developed by user fgaspar (Federico Gasparrini) and was found via the following thread
https://community.zammad.org/t/re-migration-from-rt/8660 - This was the url that was provided me by a teammate, but I got a 404 following it.

fgaspar's code had very few comments, so had to do some reverse engineering to figure some of the stuff out...

## Modifications from Original Code
* Combined the three separate scripts into a single utility that takes a subcommand argument
* Added --verbose flag (not really implemented yet though)
* Added --debug flag that provides extra debugging output
* Added --tickets= flag that allows the user to process only certain tickets
 * =ticket#           -- specifies one ticket
 * =ticket#:ticket#   -- specifies a range of tickets
 * ='<ticket#'        -- specifies all tickets less than # (pretty sure quotes are requires to escape redirection in the shell)
 * ='>ticket#'        -- specifies all tickets greater than # (pretty sure quotes are requires to escape redirection in the shell)

### Other items:
* Had to add sql command to create the rt_zammad table that is used by the code...  Assumed the code is just two integers as they are ticket ids.

## Examples
```
php rt2zammad.php --debug --verbose --test --tickets='<20000' create-tickets
php rt2zammad.php --debug --verbose --test --tickets=10:1000 create-tickets
```
