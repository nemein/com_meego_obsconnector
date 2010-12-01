#!/usr/bin/env python

import os, sys, traceback, io, pwd, grp , re
import ConfigParser, optparse, logging, logging.handlers
import daemon
from  RuoteAMQP.workitem import Workitem
from  RuoteAMQP.participant import Participant
import urllib
import urllib2
from urllib2 import HTTPError

try:
    import json
except ImportError:
    import simplejson as json


class OBSParticipant(Participant):
    def consume(self):
        try:
            wi = self.workitem
            fields = wi.fields()
            
            # do useful stuff
            
            wi.set_result(True)
        # except HTTPError, e:
        #     logger.debug("-"*60)
        #     if hasattr(e, "code"):
        #       logger.debug(e.code)
        #     if hasattr(e, "read"):
        #       logger.debug(e.read())
        #     if hasattr(e, "reason"):
        #       logger.debug(e.reason())
        #     if hasattr(e, "headers"):
        #       logger.debug(e.headers)
        #     logger.debug("-"*60)
        except:
            wi.set_field("result", "FAILED")
            wi.set_result(False)
            raise

def main():
    p = OBSParticipant(ruote_queue='obs', amqp_host='127.0.0.1:5672',  amqp_user='boss', amqp_pass='boss', amqp_vhost='boss')
    p.register('obs', {'queue': 'obs'})

    # Enter event loop with some trial at clean exit
    try:
        p.run()
    except KeyboardInterrupt:
       sys.exit(0)
    except Exception:
       traceback.print_exc(file=sys.stdout)
       sys.exit(1)

if __name__ == "__main__":
    if daemonize:
        log = open(logfile,'a+')
        pidf = open(pidfile, "w+")
        #correct permissions in case logfile was just created
        os.fchown(log.fileno(), uid, gid)
        os.fchown(pidf.fileno(), uid, gid)
        with daemon.DaemonContext(stdout=log, stderr=log, uid=uid, gid=gid, files_preserve=[pidf]):
          pidf.write(str(os.getpid()))
          pidf.close()
          main()
    else:
        main()
