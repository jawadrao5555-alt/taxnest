#define _GNU_SOURCE
#include <dlfcn.h>
#include <netdb.h>
#include <sys/socket.h>
#include <arpa/inet.h>
#include <errno.h>
#include <string.h>
static int local_addr(const struct sockaddr *a){
    if(!a)return 0;
    if(a->sa_family==AF_UNIX)return 1;
    if(a->sa_family==AF_INET)return (ntohl(((const struct sockaddr_in*)a)->sin_addr.s_addr)>>24)==127;
    if(a->sa_family==AF_INET6){
        const struct in6_addr *ip=&((const struct sockaddr_in6*)a)->sin6_addr;
        if(IN6_IS_ADDR_LOOPBACK(ip))return 1;
        if(IN6_IS_ADDR_V4MAPPED(ip))return ip->s6_addr[12]==127;
    }
    return 0;
}
int connect(int fd,const struct sockaddr *a,socklen_t n){ static int(*real)(int,const struct sockaddr*,socklen_t); if(!real)real=dlsym(RTLD_NEXT,"connect"); if(!local_addr(a)){errno=EACCES;return -1;} return real(fd,a,n); }
int getaddrinfo(const char *node,const char *svc,const struct addrinfo *h,struct addrinfo **r){ static int(*real)(const char*,const char*,const struct addrinfo*,struct addrinfo**); if(!real)real=dlsym(RTLD_NEXT,"getaddrinfo"); if(node&&strcmp(node,"localhost")&&strcmp(node,"127.0.0.1")&&strcmp(node,"::1"))return EAI_NONAME; return real(node,svc,h,r); }
ssize_t sendto(int fd,const void *buf,size_t len,int flags,const struct sockaddr *dest,socklen_t n){
    static ssize_t(*real)(int,const void*,size_t,int,const struct sockaddr*,socklen_t);
    if(!real)real=dlsym(RTLD_NEXT,"sendto");
    if(dest&&!local_addr(dest)){errno=EACCES;return -1;}
    return real(fd,buf,len,flags,dest,n);
}
ssize_t sendmsg(int fd,const struct msghdr *msg,int flags){
    static ssize_t(*real)(int,const struct msghdr*,int);
    if(!real)real=dlsym(RTLD_NEXT,"sendmsg");
    if(msg&&msg->msg_name&&!local_addr(msg->msg_name)){errno=EACCES;return -1;}
    return real(fd,msg,flags);
}
int sendmmsg(int fd,struct mmsghdr *messages,unsigned int count,int flags){
    static int(*real)(int,struct mmsghdr*,unsigned int,int);
    if(!real)real=dlsym(RTLD_NEXT,"sendmmsg");
    for(unsigned int i=0;i<count;i++){
        if(messages[i].msg_hdr.msg_name&&!local_addr(messages[i].msg_hdr.msg_name)){
            errno=EACCES;return -1;
        }
    }
    return real(fd,messages,count,flags);
}