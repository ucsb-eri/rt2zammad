# rt2zammad
RT Export + Zammad Import

## Description
The code was originally developed by zammad community user fgaspar (Federico Gasparrini) and was found via the following thread
https://community.zammad.org/t/re-migration-from-rt/8660 - This was the url that was provided me by a teammate, but I got a 404 following it.
https://community.zammad.org/t/re-merge-tickets-with-api/8939 - this is another link that 

The original code had very few comments since, like this reworking, it will be used once and then abandoned.
Because of that I had to do some reverse engineering to figure some of the stuff out.

I did find a few structural issues with the code, which I resolved in various fashions.

### Notes:
This code will probably never be completely polished as once we are able to complete our export/import "successfully enough", it will get set to the side and likely never used again.

We used a separate import account in Zammad to run the api against.  Some quick points:
* Zammad should be put into import_mode to allow dates to get punched in correctly
  * ```zammad run rails c -r "Setting.set('import_mode',true)"```
* Account used to import into api should have agent (to allow Requestor/Customer to be set) and admin roles (pretty sure, still testing)

## Modifications from Original Code
* Moved configuration information into a separate PHP file.  
  * An example of this file is at config.php.example, a copy should be made to config.php and then modified to fit the use case
* Combined the three separate scripts into a single utility that takes a subcommand argument
  * create-tickets  - create tickets
  * assign-customer - believe this assigns customers/requestors after import
    * may not be needed if requestors are attached correctly during create-tickets (not happening so far)
    * seems to require a zammad db to be in same dbms as RT db (only users table required) to provide a mapping of user/customer ids from '''rt''' to '''zammad'''
  * merge-tickets   - need to scope out what this actually does
* Added --verbose flag (not really implemented yet though)
* Added --debug flag that provides extra debugging output
* Added --test flag that does a sorta dry run so that the destination API does not get any requests
* Added --tickets= flag that allows the user to process only certain tickets
  * --tickets=ticket#           -- specifies one ticket
  * --tickets=ticket#:ticket#   -- specifies a range of tickets
  * --tickets='<ticket#'        -- specifies all tickets less than # (pretty sure quotes are requires to escape redirection in the shell)
  * --tickets='>ticket#'        -- specifies all tickets greater than # (pretty sure quotes are requires to escape redirection in the shell)
* Added --merge flag that changes the transaction queries to use RT Ticket.EffectiveId instead of Ticket.id when building the queries for processing transactions
  * This flag effectively causes tickets merged in RT to be imported directly as merged tickets
  * This gets around some issues with the ticket_merge api calls which I was never able to sort out


### Other items:
* Had to add sql command to create the rt_zammad table that is used by the code...  Assumed the code is just two integers as they are ticket ids.

## Examples
```
php rt2zammad.php --debug --verbose --test --tickets='<20000' create-tickets
php rt2zammad.php --debug --verbose --test --tickets=10:1000 create-tickets
```
